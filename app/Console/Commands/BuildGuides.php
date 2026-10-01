<?php

namespace App\Console\Commands;

use App\Support\Guides;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Fabrique les PDF des guides (A4, couverture, sommaire, signature) : le texte Markdown est rendu en HTML par la vue
 * `guides.print`, puis imprimé par Chrome ou Edge sans interface. À relancer après chaque changement d'un guide ;
 * les PDF des guides publics vont dans public/documents/, ceux de l'équipe dans resources/guides/pdf/ (jamais publics).
 */
class BuildGuides extends Command
{
    protected $signature = 'guides:build {guide? : client, developpeur, admin ou super-admin (tous par défaut)} {--url= : adresse publique du site, ex. https://kouma.site} {--email= : e-mail de contact à imprimer (sinon celui des Paramètres)} {--whatsapp= : numéro WhatsApp à imprimer (sinon celui des Paramètres)} {--browser= : chemin de Chrome ou Edge}';

    protected $description = 'Fabrique les PDF des guides (client, développeur, équipe, super admin)';

    private string $siteUrl = '';

    private string $email = '';

    private string $whatsapp = '';

    public function handle(): int
    {
        $browser = $this->findBrowser();
        if (! $browser) {
            $this->error('Aucun Chrome ni Edge trouvé. Installez-en un, ou indiquez son chemin avec --browser="C:\\...\\msedge.exe" (ou la variable GUIDES_BROWSER).');

            return self::FAILURE;
        }

        // Les PDF se fabriquent souvent sur un poste local : l'adresse du site doit être la vraie, jamais « localhost ».
        $url = $this->publicUrl();
        if (! $url) {
            $this->error('Adresse publique du site introuvable (APP_URL pointe sur un poste local). Relancez avec --url=https://votre-domaine (ou définissez GUIDES_PUBLIC_URL).');

            return self::FAILURE;
        }
        $this->siteUrl = $url;
        // Les PDF se fabriquent sur un poste local, dont les Paramètres ne sont pas ceux du site en ligne : on peut donc imposer les coordonnées.
        $this->email = trim((string) ($this->option('email') ?: \App\Support\Contact::email()));
        $whatsapp = preg_replace('/\D/', '', (string) ($this->option('whatsapp') ?: \App\Support\Contact::whatsapp()));
        $this->whatsapp = $whatsapp !== '' ? '+'.$whatsapp : '';
        $this->line('Coordonnées inscrites dans les PDF : '.$url.' | e-mail : '.($this->email ?: 'aucun').' | WhatsApp : '.($this->whatsapp ?: 'aucun'));

        $keys = $this->argument('guide') ? [$this->argument('guide')] : array_keys(Guides::all());
        foreach ($keys as $key) {
            if (! Guides::exists($key)) {
                $this->error("Guide inconnu : {$key}.");

                return self::FAILURE;
            }
        }

        $work = storage_path('app/guides-build');
        File::ensureDirectoryExists($work);

        $failed = 0;
        foreach ($keys as $key) {
            $failed += $this->build($key, $browser, $work) ? 0 : 1;
        }

        File::deleteDirectory($work);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function build(string $key, string $browser, string $work): bool
    {
        $guide = Guides::all()[$key];
        $data = Guides::render($key);

        $html = view('guides.print', [
            'guide' => $guide,
            'data' => $data,
            'fontBase' => $this->fileUrl(resource_path('guides/fonts')).'/',
            'siteUrl' => $this->siteUrl,
            'contactEmail' => $this->email,
            'contactWhatsapp' => $this->whatsapp,
            'edition' => Carbon::parse($data['updated'])->locale('fr')->isoFormat('D MMMM YYYY'),
        ])->render();

        // Garde-fou : un PDF qui parle d'un poste local ne doit jamais partir.
        if (preg_match('#localhost|127\.0\.0\.1|\[::1\]#i', strip_tags($html))) {
            $this->error("{$guide['pdf']} : le texte contient une adresse locale, PDF non fabriqué.");

            return false;
        }

        $source = $work.DIRECTORY_SEPARATOR.$key.'.html';
        file_put_contents($source, $html);

        $target = Guides::pdfPath($key);
        File::ensureDirectoryExists(dirname($target));
        @unlink($target);

        $process = new Process([
            $browser, '--headless=new', '--disable-gpu', '--no-sandbox', '--no-pdf-header-footer',
            '--user-data-dir='.$work.DIRECTORY_SEPARATOR.'profile-'.$key,
            '--run-all-compositor-stages-before-draw', '--virtual-time-budget=15000',
            '--print-to-pdf='.$target, $this->fileUrl($source),
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! is_file($target) || ! str_starts_with((string) file_get_contents($target, false, null, 0, 5), '%PDF')) {
            $this->error("{$guide['pdf']} : échec. ".trim($process->getErrorOutput() ?: $process->getOutput()));

            return false;
        }

        $this->info(sprintf('%s : %d pages, %s Ko, %d mots.', $guide['pdf'], $this->pages($target), number_format(filesize($target) / 1024, 0, ',', ' '), $data['words']));

        return true;
    }

    /** L'adresse publique : l'option, la variable d'environnement, ou l'adresse de la marque si elle n'est pas locale. */
    private function publicUrl(): ?string
    {
        foreach ([$this->option('url'), getenv('GUIDES_PUBLIC_URL') ?: null, app('platform.brand')['url'] ?? null] as $candidate) {
            if (! $candidate) {
                continue;
            }
            $host = strtolower((string) parse_url((string) $candidate, PHP_URL_HOST));
            if ($host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true) && ! preg_match('/\.(test|local|localhost|example|invalid)$/', $host)) {
                return rtrim((string) $candidate, '/');
            }
        }

        return null;
    }

    /** Nombre de pages, lu dans la structure du PDF (suffisant pour contrôler le résultat). */
    private function pages(string $pdf): int
    {
        preg_match_all('#/Type\s*/Page(?![a-z])#', (string) file_get_contents($pdf), $m);

        return count($m[0]);
    }

    private function fileUrl(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return 'file:///'.ltrim($path, '/');
    }

    private function findBrowser(): ?string
    {
        $candidates = array_filter([
            $this->option('browser'),
            getenv('GUIDES_BROWSER') ?: null,
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ]);

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
