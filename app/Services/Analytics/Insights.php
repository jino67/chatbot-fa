<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSession;

/**
 * Transforme les chiffres en phrases : ce qui monte ou baisse, le moment le plus chargé, ce qui ne marche pas.
 * Chaque conseil a un niveau (good, info, warn) et, quand c'est utile, l'onglet où creuser.
 * Les seuils sont volontairement prudents : sous quelques dizaines de visites, on préfère se taire que dire n'importe quoi.
 */
class Insights
{
    /** @return list<array{level:string,title:string,text:string,tab:?string}> */
    public function for(Stats $stats): array
    {
        $out = [];
        $now = $stats->overview();

        if ($now['sessions'] < 10) {
            return [[
                'level' => 'info',
                'title' => 'Pas encore assez de visites pour conclure',
                'text' => 'Les conseils apparaissent dès une dizaine de visites sur la période. En attendant, les chiffres restent exacts, mais trop peu nombreux pour en tirer des règles.',
                'tab' => null,
            ]];
        }

        $before = $stats->previous()->overview();

        // 1. Tendance
        if ($before['sessions'] >= 10) {
            $change = round(100 * ($now['sessions'] - $before['sessions']) / $before['sessions']);
            if ($change >= 15) {
                $out[] = $this->add('good', 'Les visites progressent', "{$now['sessions']} visites, soit {$change} % de plus que sur la période précédente.", 'apercu');
            } elseif ($change <= -15) {
                $out[] = $this->add('warn', 'Les visites reculent', "{$now['sessions']} visites, soit ".abs($change).' % de moins que sur la période précédente. Regardez la provenance pour voir quel canal a faibli.', 'acquisition');
            }
        }

        // 2. Affluence
        $heat = $stats->heatmap();
        if ($heat['peak'] && $heat['total'] >= 20) {
            $hour = $heat['peak_hour'];
            $out[] = $this->add('info', 'Le moment le plus chargé', "Les visites se concentrent le {$this->day($heat['peak_day'])} et surtout vers {$hour} h (heure de la plateforme). C'est le bon moment pour publier un message ou être joignable.", 'affluence');
        }

        // 3. Provenance
        $sources = $stats->breakdown('source', 5);
        if ($sources) {
            $top = $sources[0];
            $share = (int) round(100 * $top['sessions'] / max(1, $now['sessions']));
            if ($share >= 50 && count($sources) > 1) {
                $out[] = $this->add('info', 'Une source domine', "{$share} % des visites viennent de « {$top['label']} ». Un seul canal fait tenir le trafic : il vaut la peine d'en développer un deuxième.", 'acquisition');
            }

            $best = collect($sources)->where('sessions', '>=', 5)->sortByDesc('conversion_rate')->first();
            if ($best && $best['conversion_rate'] > 0 && $best['key'] !== $top['key']) {
                $out[] = $this->add('good', 'Un canal qui transforme mieux', "« {$best['label']} » amène moins de visites mais {$best['conversion_rate']} % d'entre elles aboutissent (contre {$now['conversion_rate']} % en moyenne).", 'acquisition');
            }

            $ai = collect($sources)->firstWhere('key', 'ai');
            if ($ai) {
                $out[] = $this->add('good', 'Des visiteurs arrivent depuis des assistants d\'IA', "{$ai['sessions']} visite(s) viennent d'un assistant d'IA : le contenu est repris et cité. Gardez les pages de ressources précises et à jour.", 'acquisition');
            }
        }

        // 4. Téléphone
        $devices = collect($stats->breakdown('device', 3));
        $mobile = $devices->firstWhere('key', 'mobile');
        if ($mobile) {
            $share = (int) round(100 * $mobile['sessions'] / max(1, $now['sessions']));
            if ($share >= 60) {
                $out[] = $this->add('info', 'Le téléphone domine', "{$share} % des visites se font depuis un téléphone. Toute nouvelle page doit être pensée d'abord pour l'écran du téléphone.", 'acquisition');
            }
        }

        // 5. Rebond
        if ($now['bounce_rate'] >= 65 && $now['sessions'] >= 30) {
            $out[] = $this->add('warn', 'Beaucoup de visiteurs repartent sans rien faire', "{$now['bounce_rate']} % des visites se terminent sans clic ni défilement notable. Regardez les pages d'entrée les plus touchées.", 'pages');
        }

        $worst = collect($stats->pages(15))->filter(fn ($p) => $p['entries'] >= 15 && $p['bounce_rate'] >= 75)->sortByDesc('entries')->first();
        if ($worst) {
            $out[] = $this->add('warn', 'Une page d\'entrée perd ses visiteurs', "« {$worst['name']} » reçoit {$worst['entries']} entrées et {$worst['bounce_rate']} % repartent sans geste. Le titre ou le premier bouton ne retient peut-être pas.", 'pages');
        }

        // 6. Clics
        $clicks = $stats->clicks(5);
        if (! empty($clicks['cta'])) {
            $cta = $clicks['cta'][0];
            $out[] = $this->add('info', 'Le bouton le plus cliqué', "« {$cta['name']} » ({$cta['page']}) : {$cta['clicks']} clic(s) par {$cta['visitors']} visiteur(s).", 'clics');
        }
        if (! empty($clicks['rage'])) {
            $rage = $clicks['rage'][0];
            $out[] = $this->add('warn', 'Des clics répétés sur un élément', "Des visiteurs cliquent plusieurs fois de suite sur « {$rage['name']} » ({$rage['page']}) : cet élément semble ne rien faire. À vérifier.", 'clics');
        }
        $whatsapp = collect($clicks['contacts'])->firstWhere('name', 'whatsapp');
        if ($whatsapp && $whatsapp['clicks'] >= 3) {
            $out[] = $this->add('good', 'On vous écrit sur WhatsApp', "{$whatsapp['clicks']} clic(s) vers votre WhatsApp sur la période : c'est le canal que vos visiteurs choisissent.", 'clics');
        }

        // 7. Entonnoir : l'étape où l'on perd le plus
        $funnel = $stats->funnel();
        if ($funnel[0]['count'] >= 30) {
            $drop = collect($funnel)->skip(1)->sortBy('from_previous')->first();
            if ($drop && $drop['from_previous'] < 50) {
                $out[] = $this->add('warn', 'Là où l\'on perd le plus de monde', "Entre l'étape précédente et « {$drop['label']} », seulement {$drop['from_previous']} % continuent. C'est l'endroit où un effort aura le plus d'effet.", 'parcours');
            }
        }

        // 8. Vitesse
        $slow = collect($stats->vitals())->filter(fn ($v) => $v['rating'] === 'poor' && $v['samples'] >= 20)->first();
        if ($slow) {
            $out[] = $this->add('warn', 'Une mesure de vitesse est mauvaise', "{$slow['label']} : {$slow['display']} pour les trois quarts des visiteurs. Cela se ressent surtout sur téléphone avec une connexion lente.", 'experience');
        }

        // 9. Erreurs
        $errors = $stats->errors();
        if ($errors) {
            $out[] = $this->add('warn', 'Des erreurs côté navigateur', "{$errors[0]['count']} fois : « ".mb_strimwidth($errors[0]['name'], 0, 90, '…')." ». À transmettre à l'équipe technique.", 'experience');
        }

        // 10. Journée inhabituelle
        $anomaly = $this->anomaly($stats->audience);
        if ($anomaly) {
            $out[] = $anomaly;
        }

        return $out;
    }

    /** @return list<array{level:string,title:string,text:string,tab:?string}> */
    public function forClients(ClientStats $clients, ?array $rows = null): array
    {
        $rows ??= $clients->clients();
        $out = [];
        $active = $clients->active();

        if ($active['mau'] > 0) {
            $out[] = $this->add('info', 'Clients actifs', "{$active['dau']} espace(s) actif(s) aujourd'hui, {$active['wau']} cette semaine, {$active['mau']} ce mois. Régularité (jour sur mois) : {$active['stickiness']} %.", 'clients');
        }

        $risk = $clients->atRisk($rows, 5);
        if ($risk) {
            $names = collect($risk)->take(3)->pluck('name')->implode(', ');
            $out[] = $this->add('warn', count($risk).' client(s) ont décroché', "Aucune visite depuis plus de 14 jours : {$names}. Un message ou un appel de l'équipe peut les ramener.", 'clients');
        }

        $adoption = collect($clients->adoption())->filter(fn ($a) => $a['workspaces'] === 0)->first();
        if ($adoption && $active['mau'] >= 5) {
            $out[] = $this->add('info', 'Une fonction que personne n\'utilise', "« {$adoption['label']} » n'a servi à aucun client ce mois-ci. Elle manque peut-être de visibilité dans l'application.", 'clients');
        }

        $activation = $clients->activation();
        if ($activation[0]['count'] >= 5) {
            $drop = collect($activation)->skip(1)->sortBy('from_previous')->first();
            if ($drop && $drop['from_previous'] < 60) {
                $out[] = $this->add('warn', 'Les nouveaux comptes s\'arrêtent en chemin', "Après l'étape précédente, seulement {$drop['from_previous']} % arrivent à « {$drop['label']} ». Ajoutez-y un rappel ou proposez de le faire pour eux.", 'clients');
            }
        }

        return $out;
    }

    /** Hier, comparé aux mêmes jours de la semaine des quatre semaines d'avant. */
    private function anomaly(string $audience): ?array
    {
        $series = Stats::lastDays(36, $audience)->daily();
        $yesterday = $series[count($series) - 2] ?? null;
        if (! $yesterday) {
            return null;
        }

        $same = [];
        for ($i = 1; $i <= 4; $i++) {
            $same[] = $series[count($series) - 2 - 7 * $i]['sessions'] ?? 0;
        }
        $mean = array_sum($same) / 4;

        if ($mean < 5) {
            return null;
        }

        if ($yesterday['sessions'] >= 1.8 * $mean) {
            return $this->add('good', 'Un pic de visites hier', "{$yesterday['sessions']} visites hier, contre {$mean} en moyenne ce jour de la semaine. Cherchez ce qui l'a provoqué (publication, partage, e-mail) pour le refaire.", 'apercu');
        }

        if ($yesterday['sessions'] <= 0.4 * $mean) {
            return $this->add('warn', 'Une chute de visites hier', "{$yesterday['sessions']} visites hier, contre {$mean} en moyenne ce jour de la semaine. Vérifiez que le site et la mesure fonctionnent bien.", 'apercu');
        }

        return null;
    }

    private function day(?string $day): string
    {
        return mb_strtolower((string) $day);
    }

    /** @return array{level:string,title:string,text:string,tab:?string} */
    private function add(string $level, string $title, string $text, ?string $tab): array
    {
        return ['level' => $level, 'title' => $title, 'text' => $text, 'tab' => $tab];
    }
}
