<?php

namespace Tests\Feature;

use App\Ingestion\Extractors\FileExtractor;
use App\Ingestion\Extractors\XlsxReader;
use App\Ingestion\IngestionException;
use App\Models\Source;
use App\Retrieval\Retriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;
use ZipArchive;

/** Catalogues et tableaux : Excel, CSV, export de Meta ; colonnes comprises, prix lisibles, résumé pour le client. */
class CatalogTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function tmp(string $name, string $content): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('cat', true).'-'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    private function extract(string $name, string $content, ?string $currency = 'XOF')
    {
        $path = $this->tmp($name, $content);

        return app(FileExtractor::class)->extract($path, pathinfo($name, PATHINFO_EXTENSION), $name, $currency);
    }

    /**
     * Fabrique un .xlsx minimal. Une cellule vide ou null est absente du fichier (comme dans Excel) ; un tableau est un
     * texte à mise en forme partielle ; « inline:... » est un texte en ligne.
     *
     * @param  list<array{name:string, rows:list<list<mixed>>, hidden?:bool}>  $sheets
     */
    private function xlsx(array $sheets): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('xl', true).'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $shared = [];
        $workbook = '';
        $rels = '';

        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $rowsXml = '';
            foreach ($sheet['rows'] as $r => $row) {
                $cells = '';
                foreach ($row as $c => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $ref = chr(65 + $c).($r + 1);
                    if (is_int($value) || is_float($value)) {
                        $cells .= "<c r=\"{$ref}\"><v>{$value}</v></c>";
                    } elseif (is_string($value) && str_starts_with($value, 'inline:')) {
                        $cells .= "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>".htmlspecialchars(substr($value, 7)).'</t></is></c>';
                    } else {
                        $key = json_encode($value);
                        $index = array_search($key, array_column($shared, 'key'), true);
                        if ($index === false) {
                            $shared[] = ['key' => $key, 'value' => $value];
                            $index = count($shared) - 1;
                        }
                        $cells .= "<c r=\"{$ref}\" t=\"s\"><v>{$index}</v></c>";
                    }
                }
                $rowsXml .= '<row r="'.($r + 1).'">'.$cells.'</row>';
            }

            $zip->addFromString("xl/worksheets/sheet{$n}.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rowsXml.'</sheetData></worksheet>');
            $state = ($sheet['hidden'] ?? false) ? ' state="hidden"' : '';
            $workbook .= '<sheet name="'.htmlspecialchars($sheet['name'])."\" sheetId=\"{$n}\"{$state} r:id=\"rId{$n}\"/>";
            $rels .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
        }

        $strings = '';
        foreach ($shared as $item) {
            $strings .= is_array($item['value'])
                ? '<si>'.implode('', array_map(fn ($part) => '<r><t xml:space="preserve">'.htmlspecialchars($part).'</t></r>', $item['value'])).'</si>'
                : '<si><t xml:space="preserve">'.htmlspecialchars($item['value']).'</t></si>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$workbook.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$strings.'</sst>');
        $zip->close();

        return $path;
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{202F}");
    }

    /* ---------- Excel ---------- */

    public function test_xlsx_cells_keep_their_column_with_gaps_shared_inline_and_rich_text_and_hidden_sheets_are_skipped(): void
    {
        $path = $this->xlsx([
            ['name' => 'Produits', 'rows' => [
                ['Nom', 'Prix', 'Stock'],
                ['Robe wax', 18000, 5],
                [['Pagne ', 'Faso'], null, 'inline:oui'],
                [null, null, 3.5],
            ]],
            ['name' => 'Brouillon', 'hidden' => true, 'rows' => [['secret', 1]]],
        ]);

        $sheets = (new XlsxReader)->read($path);

        $this->assertCount(1, $sheets, 'la feuille cachée est ignorée');
        $this->assertSame('Produits', $sheets[0]['name']);
        $this->assertSame([
            ['Nom', 'Prix', 'Stock'],
            ['Robe wax', '18000', '5'],
            ['Pagne Faso', '', 'oui'],
            ['', '', '3.5'],
        ], $sheets[0]['rows'], 'une cellule absente garde sa colonne ; le texte à mise en forme partielle est recollé');
    }

    public function test_a_broken_or_old_excel_file_gets_a_clear_message(): void
    {
        try {
            $this->extract('casse.xlsx', 'ceci n\'est pas un classeur');
            $this->fail('un fichier abîmé doit être refusé');
        } catch (IngestionException $e) {
            $this->assertStringContainsString('illisible ou abîmé', $e->getMessage());
        }

        try {
            $this->extract('ancien.xls', "\xD0\xCF\x11\xE0");
            $this->fail('le format .xls doit être refusé avec une explication');
        } catch (IngestionException $e) {
            $this->assertStringContainsString('.xlsx ou CSV', $e->getMessage());
        }
    }

    public function test_an_excel_catalog_uses_title_rows_section_rows_and_numbers(): void
    {
        $path = $this->xlsx([['name' => 'Catalogue', 'rows' => [
            ['Boutique Awa'],
            ['Catalogue octobre'],
            ['Produit', 'Prix', 'Disponibilité'],
            ['ROBES'],
            ['Robe wax', 18000, 'En stock'],
            ['Robe bazin', 25000, 'Sur commande'],
            ['PAGNES'],
            ['Pagne Faso Dan Fani', 12500.5, 'Rupture'],
        ]]]);

        $doc = app(FileExtractor::class)->extract($path, 'xlsx', 'catalogue.xlsx', 'XOF');

        $this->assertStringContainsString("## ROBES\n\nRobe wax : ".$this->money(18000).' FCFA. En stock.', $doc->text);
        $this->assertStringContainsString('Robe bazin : '.$this->money(25000).' FCFA. Sur commande.', $doc->text);
        $this->assertStringContainsString("## PAGNES\n\nPagne Faso Dan Fani : 12\u{202F}500,50 FCFA. Rupture de stock.", $doc->text);
        $this->assertStringContainsString('3 produits ou services en 2 catégories (ROBES : 2, PAGNES : 1)', $doc->text);
        $this->assertSame(3, $doc->meta['products']);
        $this->assertStringNotContainsString('Boutique Awa', $doc->text, 'les lignes de titre au-dessus de l\'en-tête ne sont pas des produits');
    }

    public function test_each_visible_sheet_becomes_a_category_when_there_is_no_category_column(): void
    {
        $path = $this->xlsx([
            ['name' => 'Robes', 'rows' => [['Nom', 'Prix'], ['Robe wax', 18000]]],
            ['name' => 'Chaussures', 'rows' => [['Nom', 'Prix'], ['Sandale cuir', 9000]]],
            ['name' => 'Brouillon', 'hidden' => true, 'rows' => [['Nom', 'Prix'], ['Ne pas lire', 1]]],
        ]);

        $doc = app(FileExtractor::class)->extract($path, 'xlsx', 'boutique.xlsx', 'XOF');

        $this->assertStringContainsString("## Robes\n\nRobe wax", $doc->text);
        $this->assertStringContainsString("## Chaussures\n\nSandale cuir", $doc->text);
        $this->assertStringNotContainsString('Ne pas lire', $doc->text);
    }

    /* ---------- CSV ---------- */

    public function test_a_merchant_csv_is_understood_prices_stock_categories_and_private_columns(): void
    {
        $csv = <<<'CSV'
Boutique Awa;;;;;
Catalogue octobre;;;;;
Nom du produit;Catégorie;Prix (FCFA);Stock;Description;Prix d'achat
Robe wax Awa;Robes;18 000;En stock;"Robe longue en wax, tailles S à XL";9000
Pagne Faso Dan Fani;Pagnes;12.500;oui;Pagne tissé à la main;7000
Sac en cuir;Accessoires;25000 FCFA;0;;12000
Écharpe;Accessoires;Sur devis;;;
Exemple : produit 1 (à supprimer);Catégorie A;5000;En stock;;
CSV;

        $doc = $this->extract('catalogue.csv', $csv);
        $t = $doc->text;

        $this->assertStringContainsString('## Robes', $t);
        $this->assertStringContainsString('Robe wax Awa : '.$this->money(18000).' FCFA. En stock. Description : Robe longue en wax, tailles S à XL.', $t);
        $this->assertStringContainsString('Pagne Faso Dan Fani : '.$this->money(12500).' FCFA. En stock.', $t);
        $this->assertStringContainsString('Sac en cuir : '.$this->money(25000).' FCFA. Rupture de stock.', $t);
        $this->assertStringContainsString('Écharpe : Sur devis.', $t);
        $this->assertStringContainsString('4 produits ou services en 3 catégories (Robes : 1, Pagnes : 1, Accessoires : 2). Prix de '.$this->money(12500).' FCFA à '.$this->money(25000).' FCFA.', $t);

        foreach (['9000', '7000', '12000', 'Exemple'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $t, "« {$hidden} » ne doit jamais être lu (prix d'achat ou ligne d'exemple)");
        }

        $this->assertSame(4, $doc->meta['products']);
        $notes = implode("\n", $doc->meta['notes']);
        $this->assertStringContainsString("Colonnes non lues par prudence : Prix d'achat", $notes);
        $this->assertStringContainsString("1 ligne d'exemple ignorée", $notes);
    }

    public function test_a_french_excel_csv_in_windows_1252_with_a_bom_is_read_correctly(): void
    {
        $csv = "Nom;Prix;Description\r\nÉcharpe en soie;15 € ;Douce et légère\r\nCafé moulu;3,50 €;Arabica\r\n";
        $encoded = mb_convert_encoding($csv, 'Windows-1252', 'UTF-8');

        $doc = $this->extract('ancien-excel.csv', $encoded, 'EUR');

        $this->assertStringContainsString('Écharpe en soie : 15 €. Description : Douce et légère.', $doc->text);
        $this->assertStringContainsString('Café moulu : 3,50 €. Description : Arabica.', $doc->text);

        $withBom = $this->extract('bom.csv', "\xEF\xBB\xBF".'Nom;Prix'."\n".'Thé;800', 'XOF');
        $this->assertStringContainsString('Thé : 800 FCFA.', $withBom->text, 'le BOM ne colle pas à la première colonne');
    }

    public function test_the_catalog_export_of_meta_commerce_manager_is_understood_as_is(): void
    {
        $csv = <<<'CSV'
id,title,description,availability,condition,price,link,image_link,brand,sale_price
SKU1,Robe wax,"Robe en wax, taille unique",in stock,new,18000 XOF,https://boutique.test/p/1,https://boutique.test/i/1.jpg,Awa Mode,15000 XOF
SKU2,Pagne,Pagne tissé,out of stock,new,12500 XOF,https://boutique.test/p/2,https://boutique.test/i/2.jpg,Awa Mode,
CSV;

        $t = $this->extract('meta.csv', $csv)->text;

        $this->assertStringContainsString('Robe wax (réf. SKU1) : '.$this->money(15000).' FCFA en promotion (prix normal : '.$this->money(18000).' FCFA). En stock. Marque : Awa Mode. Description : Robe en wax, taille unique. Lien : https://boutique.test/p/1.', $t);
        $this->assertStringContainsString('Pagne (réf. SKU2) : '.$this->money(12500).' FCFA. Rupture de stock. Marque : Awa Mode. Description : Pagne tissé.', $t);
        $this->assertStringNotContainsString('image_link', $t);
        $this->assertStringNotContainsString('.jpg', $t, 'les liens d\'images ne servent pas à répondre');
        $this->assertStringNotContainsString('new', $t);
    }

    public function test_a_table_that_is_not_a_catalog_is_still_read_line_by_line_with_a_note(): void
    {
        $doc = $this->extract('livraison.csv', "Ville;Délai;Frais\nBobo-Dioulasso;48 h;2000\nKoudougou;72 h;3000");

        $this->assertStringContainsString('Ville : Bobo-Dioulasso | Délai : 48 h | Frais : 2000', $doc->text);
        $this->assertStringNotContainsString('Catalogue', $doc->text);
        $this->assertArrayNotHasKey('products', array_filter($doc->meta));
        $this->assertStringContainsString('lu ligne par ligne', implode(' ', $doc->meta['notes']));
    }

    public function test_the_notes_flag_missing_prices_duplicates_and_nameless_rows(): void
    {
        $doc = $this->extract('notes.csv', "Nom;Prix;Description\nRobe;18000;a\nRobe;18000;b\nFoulard;;sans prix\n;500;orphelin\nPagne;12000;c");

        $notes = implode("\n", $doc->meta['notes']);
        $this->assertStringContainsString('1 produit sans prix', $notes);
        $this->assertStringContainsString('Foulard', $notes);
        $this->assertStringContainsString('Noms en double', $notes);
        $this->assertStringContainsString('1 ligne sans nom ignorée', $notes);
        $this->assertStringContainsString('Foulard : Description : sans prix.', $doc->text, 'un produit sans prix reste connu de l\'assistant');
        $this->assertSame(4, $doc->meta['products'], 'la ligne sans nom n\'est pas un produit, les doublons sont gardés');
    }

    public function test_a_prices_default_currency_comes_from_the_workspace_and_a_price_can_carry_its_own(): void
    {
        $csv = "Nom;Prix\nCafé;3,50\nThé;800 FCFA";

        $euro = $this->extract('devise.csv', $csv, 'EUR')->text;

        $this->assertStringContainsString('Café : 3,50 €.', $euro);
        $this->assertStringContainsString('Thé : 800 FCFA.', $euro, 'la devise écrite dans la cellule prime');
    }

    public function test_a_file_with_only_the_template_examples_asks_for_real_products(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionMessage("lignes d'exemple");

        $this->extract('modele.csv', "Nom;Catégorie;Prix;Disponibilité;Description\nExemple : produit 1 (à supprimer);Catégorie A;5000 FCFA;En stock;x\nExemple : produit 2 (à supprimer);Catégorie A;12000 FCFA;Sur commande;");
    }

    /* ---------- De bout en bout ---------- */

    public function test_an_uploaded_catalog_is_summarised_and_found_by_the_assistant(): void
    {
        [, $user, $bot] = $this->tenant();
        $csv = "Nom;Catégorie;Prix;Stock;Description\nRobe wax Awa;Robes;18000;En stock;Robe longue en wax\nPagne Faso Dan Fani;Pagnes;12500;En stock;Pagne tissé\nFoulard;Accessoires;;oui;Foulard en soie";

        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'file',
            'file' => UploadedFile::fake()->createWithContent('catalogue.csv', $csv),
        ])->assertSessionHasNoErrors();

        $source = $bot->sources()->firstOrFail();
        $this->assertSame(Source::READY, $source->status, (string) $source->error);
        $this->assertSame(3, $source->stats['products']);
        $this->assertStringContainsString('1 produit sans prix', implode(' ', $source->stats['notes']));

        $found = app(Retriever::class)->retrieve($bot, 'Combien coûte la robe wax ?');
        $this->assertTrue(collect($found)->contains(fn ($chunk) => str_contains($chunk->content, 'Robe wax Awa : '.$this->money(18000).' FCFA')));

        $page = $this->actingAs($user)->get(route('sources.index', $bot))->assertOk();
        $page->assertSee('3 produit(s) lu(s)');
        $page->assertSee('1 produit sans prix');
        $page->assertSee('Télécharger un modèle de catalogue');
    }

    public function test_an_excel_file_is_accepted_by_the_upload_form(): void
    {
        [, $user, $bot] = $this->tenant();
        $path = $this->xlsx([['name' => 'Produits', 'rows' => [['Nom', 'Prix'], ['Robe wax', 18000]]]]);

        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'file',
            'file' => new UploadedFile($path, 'catalogue.xlsx', null, null, true),
        ])->assertSessionHasNoErrors();

        $source = $bot->sources()->firstOrFail();
        $this->assertSame(Source::READY, $source->status, (string) $source->error);
        $this->assertSame(1, $source->stats['products']);
    }

    /* ---------- Modèle à télécharger ---------- */

    public function test_the_template_opens_in_french_excel_and_cannot_be_downloaded_for_another_clients_assistant(): void
    {
        [, $user, $bot] = $this->tenant();

        $response = $this->actingAs($user)->get(route('sources.template', $bot))->assertOk();
        $body = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'BOM UTF-8 : Excel affiche les accents');
        $this->assertStringContainsString('"Nom";"Catégorie";"Prix";"Disponibilité";"Description"', $body);
        $this->assertStringContainsString('(à supprimer)', $body);
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        [, $stranger] = $this->tenant('Autre entreprise');
        $this->actingAs($stranger)->get(route('sources.template', $bot))->assertNotFound();
    }
}
