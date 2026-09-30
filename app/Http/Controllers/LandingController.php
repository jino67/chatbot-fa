<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlatformSettings;
use Illuminate\Http\Response;

/** Page d'accueil publique, tarifs, robots.txt et plan du site. */
class LandingController extends Controller
{
    public function __invoke(PlatformSettings $settings)
    {
        return view('landing', [
            'plans' => Plan::where('is_public', true)->orderBy('sort')->get(),
            'landingBotKey' => $settings->get('marketing.landing_bot_key'),
            'sectors' => config('sectors'),
        ]);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Disallow: /bots',
            'Disallow: /billing',
            'Disallow: /demo/',
            'Disallow: /api/',
            'Disallow: /webhooks/',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain']);
    }

    public function sitemap(): Response
    {
        $urls = [route('home'), route('legal.terms'), route('legal.privacy'), route('register'), route('login')];
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', array_map(fn ($u) => '<url><loc>'.e($u).'</loc></url>', $urls)).'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
