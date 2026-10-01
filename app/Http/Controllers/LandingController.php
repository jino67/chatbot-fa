<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlatformSettings;
use App\Support\SeoPages;
use Illuminate\Http\Response;

/** Page d'accueil publique, robots.txt, plan du site et résumé pour les assistants d'IA (llms.txt). */
class LandingController extends Controller
{
    public function __invoke(PlatformSettings $settings)
    {
        return view('landing', [
            'plans' => Plan::forBusiness()->where('is_public', true)->orderBy('sort')->get(),
            'landingBotKey' => $settings->get('marketing.landing_bot_key'),
            'sectors' => config('sectors'),
        ]);
    }

    public function robots(): Response
    {
        // Espaces privés et adresses techniques : hors des résultats. Les pages de connexion restent explorables
        // pour que leur balise « noindex » soit lue.
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Disallow: /bots',
            'Disallow: /billing',
            'Disallow: /profile',
            'Disallow: /demandes',
            'Disallow: /alertes',
            'Disallow: /import',
            'Disallow: /developers/keys',
            'Disallow: /demo/',
            'Disallow: /api/',
            'Disallow: /webhooks/',
            'Disallow: /media/',
            'Disallow: /devise/',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $modified = SeoPages::lastModified();
        $entries = [
            [route('home'), $modified],
            [route('seo.hub'), $modified],
            [route('register'), $this->viewDate('auth.register')],
            [route('developers'), $this->viewDate('developers')],
            [route('legal.terms'), $this->viewDate('legal.terms')],
            [route('legal.privacy'), $this->viewDate('legal.privacy')],
        ];
        foreach (SeoPages::all() as $page) {
            $entries[] = [SeoPages::url($page['key']), $page['updated']];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($entries as [$loc, $date]) {
            $xml .= '  <url><loc>'.e($loc).'</loc><lastmod>'.$date.'</lastmod></url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** Résumé lisible par les assistants d'IA : qui nous sommes, ce que nous faisons, où lire les détails. */
    public function llms(PlatformSettings $settings, SeoPages $pages): Response
    {
        $brand = $settings->brand();
        $lines = [
            '# '.$brand['name'],
            '',
            '> '.$brand['tagline'].'. Plateforme pour petites et moyennes entreprises d\'Afrique francophone : un assistant répond aux clients sur WhatsApp et sur le site web, uniquement à partir des documents de l\'entreprise, et passe la main à une personne quand il le faut. Prix en FCFA, franc comorien, euro, dollar et dirham ; paiement par Mobile Money ou virement.',
            '',
            '## Pages principales',
            '- ['.$brand['name'].' : accueil]('.url('/').') : présentation, tarifs et questions fréquentes',
            '- [Ressources]('.route('seo.hub').') : tous les guides, solutions, métiers et pays',
            '- [API pour développeurs]('.route('developers').') : appeler l\'assistant depuis une application',
        ];
        foreach ($pages->hub()['groups'] as $group) {
            $lines[] = '';
            $lines[] = '## '.$group['title'];
            foreach ($group['pages'] as $page) {
                $lines[] = '- ['.$page['label'].']('.$page['url'].') : '.$page['description'];
            }
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Date de dernière modification d'une vue, pour le plan du site. */
    private function viewDate(string $view): string
    {
        $file = view()->getFinder()->find($view);

        return date('Y-m-d', min(filemtime($file), time()));
    }
}
