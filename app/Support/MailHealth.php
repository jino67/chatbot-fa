<?php

namespace App\Support;

/**
 * Pourquoi des e-mails finissent dans les courriers indésirables : on lit la zone DNS du domaine d'expédition (SPF, DKIM, DMARC)
 * et le réglage de l'expéditeur, et on dit en clair ce qui manque et quoi faire. Rien n'est envoyé. La recherche DNS est
 * injectable pour les tests ; elle ne remplace pas un essai réel (Administration, E-mails, « M'envoyer cet e-mail »).
 */
final class MailHealth
{
    /** Les noms de sélecteur DKIM les plus courants chez les hébergeurs et les services d'envoi. */
    public const DKIM_SELECTORS = ['default', 'mail', 'dkim', 'selector1', 'selector2', 's1', 's2', 'k1', 'google', 'lws', 'smtp', 'email'];

    /** @var callable(string,int):array<int,array<string,mixed>> */
    private $lookup;

    /** @param (callable(string,int):array<int,array<string,mixed>>)|null $lookup */
    public function __construct(private readonly ?string $address = null, ?callable $lookup = null, private readonly ?string $siteHost = null)
    {
        $this->lookup = $lookup ?? function (string $host, int $type): array {
            $records = @dns_get_record($host, $type);

            return is_array($records) ? $records : [];
        };
    }

    public function address(): string
    {
        return (string) ($this->address ?? config('mail.from.address'));
    }

    public function domain(): ?string
    {
        $domain = mb_strtolower((string) substr(strrchr($this->address(), '@') ?: '', 1));

        return $domain !== '' ? $domain : null;
    }

    /**
     * @return list<array{key:string,label:string,status:string,detail:string,fix:?string}> status : ok, warn, bad ou info
     */
    public function checks(): array
    {
        $domain = $this->domain();
        $checks = [];

        $driver = (string) config('mail.default');
        $checks[] = in_array($driver, ['log', 'array'], true)
            ? $this->item('driver', 'Envoi réel', 'bad', "Le pilote « {$driver} » n'envoie rien : les messages sont écrits dans les journaux.", 'Mettez MAIL_MAILER=smtp et les identifiants de la boîte dans le fichier .env, puis lancez php artisan platform:mail-test.')
            : $this->item('driver', 'Envoi réel', 'ok', "Envoi par « {$driver} ».");

        $name = (string) config('mail.from.name');
        $checks[] = MailSender::isPlaceholder($name)
            ? $this->item('name', 'Nom de l\'expéditeur', 'warn', 'Le nom configuré est « '.($name === '' ? 'vide' : $name).' » : le destinataire verrait un faux nom. La plateforme affiche à la place « '.MailSender::name().' ».', 'Dans .env, écrivez MAIL_FROM_NAME="'.MailSender::name().'" (le nom lui-même, sans ${...}).')
            : $this->item('name', 'Nom de l\'expéditeur', 'ok', "Les messages s'affichent de la part de « {$name} ».");

        if (! $domain) {
            return [...$checks, $this->item('address', 'Adresse d\'expédition', 'bad', 'Aucune adresse d\'expédition valide.', 'Renseignez MAIL_FROM_ADDRESS dans .env.')];
        }

        $checks[] = $this->alignment($domain);
        $checks[] = $this->mx($domain);
        $checks[] = $this->spf($domain);
        $checks[] = $this->dkim($domain);
        $checks[] = $this->dmarc($domain);

        return $checks;
    }

    /** @param list<array{status:string}> $checks @return array{score:int,level:string,label:string} */
    public static function summary(array $checks): array
    {
        $bad = count(array_filter($checks, fn ($c) => $c['status'] === 'bad'));
        $warn = count(array_filter($checks, fn ($c) => $c['status'] === 'warn'));
        $score = max(0, 100 - 25 * $bad - 10 * $warn);

        return match (true) {
            $bad > 0 => ['score' => $score, 'level' => 'bad', 'label' => 'Risque élevé de courrier indésirable'],
            $warn > 0 => ['score' => $score, 'level' => 'warn', 'label' => 'À améliorer'],
            default => ['score' => $score, 'level' => 'ok', 'label' => 'Bien configuré'],
        };
    }

    /** L'adresse d'expédition doit être celle du domaine du site : sinon l'alignement exigé par DMARC échoue. */
    private function alignment(string $domain): array
    {
        $site = mb_strtolower((string) ($this->siteHost ?? parse_url((string) config('app.url'), PHP_URL_HOST)));
        $site = preg_replace('/^www\./', '', $site);

        if ($site === '' || in_array($site, ['localhost', '127.0.0.1'], true)) {
            return $this->item('alignment', 'Adresse de l\'expéditeur et site', 'info', 'Site local : non vérifié.');
        }

        $same = $domain === $site || str_ends_with($site, '.'.$domain) || str_ends_with($domain, '.'.$site);

        return $same
            ? $this->item('alignment', 'Adresse de l\'expéditeur et site', 'ok', "L'expéditeur ({$domain}) est du même domaine que le site ({$site}).")
            : $this->item('alignment', 'Adresse de l\'expéditeur et site', 'bad', "L'expéditeur est en @{$domain} mais le site est {$site} : les filtres le prennent pour une usurpation.", "Envoyez depuis une boîte de votre propre domaine ({$site}), pas depuis Gmail ou un autre domaine.");
    }

    private function mx(string $domain): array
    {
        $records = ($this->lookup)($domain, DNS_MX);

        return $records
            ? $this->item('mx', 'Réception (MX)', 'ok', 'Le domaine reçoit du courrier : '.implode(', ', array_slice(array_column($records, 'target'), 0, 2)).'.')
            : $this->item('mx', 'Réception (MX)', 'warn', 'Aucun enregistrement MX : on ne peut pas répondre à vos e-mails, et certains filtres s\'en méfient.', 'Créez la boîte e-mail chez votre hébergeur : il ajoute les MX.');
    }

    private function txt(string $host): array
    {
        return array_values(array_filter(array_map(fn ($r) => trim((string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? [])))), ($this->lookup)($host, DNS_TXT))));
    }

    private function spf(string $domain): array
    {
        $spf = array_values(array_filter($this->txt($domain), fn ($t) => stripos($t, 'v=spf1') === 0));

        return match (true) {
            $spf === [] => $this->item('spf', 'SPF (qui a le droit d\'envoyer)', 'bad', 'Aucun enregistrement SPF : n\'importe qui peut prétendre envoyer pour votre domaine, et les messages sont traités comme suspects.', "Dans la zone DNS de {$domain}, ajoutez un enregistrement TXT « v=spf1 ... ~all » avec la valeur donnée par votre hébergeur de messagerie."),
            count($spf) > 1 => $this->item('spf', 'SPF (qui a le droit d\'envoyer)', 'bad', 'Plusieurs enregistrements SPF : c\'est invalide, aucun n\'est lu.', 'Gardez un seul enregistrement TXT SPF et fusionnez les autorisations dedans.'),
            (bool) preg_match('/\s\+all\b|\s\?all\b/i', $spf[0]) =>$this->item('spf', 'SPF (qui a le droit d\'envoyer)', 'warn', 'SPF trop permissif ('.$spf[0].').', 'Terminez par « ~all » (ou « -all » quand tout est en place).'),
            default => $this->item('spf', 'SPF (qui a le droit d\'envoyer)', 'ok', $spf[0]),
        };
    }

    private function dkim(string $domain): array
    {
        foreach (self::DKIM_SELECTORS as $selector) {
            foreach ($this->txt("{$selector}._domainkey.{$domain}") as $txt) {
                if (stripos($txt, 'v=DKIM1') !== false || stripos($txt, 'p=') !== false) {
                    return $this->item('dkim', 'DKIM (signature du message)', 'ok', "Clé trouvée (sélecteur « {$selector} »).");
                }
            }
        }

        return $this->item('dkim', 'DKIM (signature du message)', 'bad', 'Aucune clé DKIM trouvée avec les noms courants : sans signature, Gmail et Outlook placent souvent le message en indésirable.', "Activez DKIM pour {$domain} dans le panneau de votre hébergeur de messagerie, puis ajoutez dans la zone DNS le TXT qu'il affiche. Le nom du sélecteur peut différer de ceux testés ici : un essai réel vers Gmail (Afficher l'original, « DKIM: PASS ») tranche.");
    }

    private function dmarc(string $domain): array
    {
        $dmarc = array_values(array_filter($this->txt("_dmarc.{$domain}"), fn ($t) => stripos($t, 'v=DMARC1') === 0));

        if ($dmarc === []) {
            return $this->item('dmarc', 'DMARC (que faire des faux messages)', 'bad', 'Aucune règle DMARC : Gmail et Yahoo l\'exigent pour les envois réguliers.', "Ajoutez un TXT « _dmarc.{$domain} » : v=DMARC1; p=none; rua=mailto:contact@{$domain} (puis « quarantine » quand SPF et DKIM sont verts).");
        }

        return preg_match('/\bp=none\b/i', $dmarc[0])
            ? $this->item('dmarc', 'DMARC (que faire des faux messages)', 'warn', 'DMARC présent mais en simple observation (p=none) : '.$dmarc[0], 'Quand SPF et DKIM sont verts, passez à p=quarantine.')
            : $this->item('dmarc', 'DMARC (que faire des faux messages)', 'ok', $dmarc[0]);
    }

    /** @return array{key:string,label:string,status:string,detail:string,fix:?string} */
    private function item(string $key, string $label, string $status, string $detail, ?string $fix = null): array
    {
        return compact('key', 'label', 'status', 'detail', 'fix');
    }
}
