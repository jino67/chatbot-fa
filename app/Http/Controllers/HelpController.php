<?php

namespace App\Http\Controllers;

use App\Services\PlatformSettings;
use App\Support\Guides;
use Illuminate\Contracts\View\View;

/**
 * Pages d'aide publiques : le guide d'utilisation (entreprises) et le guide du développeur, lus depuis
 * resources/guides/, avec leur PDF à télécharger. Le chat web de Kouma y est proposé : l'assistant de la page d'accueil.
 */
class HelpController extends Controller
{
    public function index(PlatformSettings $settings): View
    {
        $guides = collect(Guides::all())->filter(fn ($g) => $g['public'])
            ->map(fn ($g, $key) => $g + ['key' => $key, 'url' => route($g['route']), 'pdf_url' => Guides::pdfUrl($key), 'updated' => Guides::updated($key)])
            ->values();

        return view('help.index', [
            'guides' => $guides,
            'landingBotKey' => $settings->get('marketing.landing_bot_key'),
        ]);
    }

    public function show(PlatformSettings $settings, string $guide): View
    {
        abort_unless((Guides::all()[$guide]['public'] ?? false), 404);

        return view('help.guide', [
            'key' => $guide,
            'meta' => Guides::all()[$guide],
            'guide' => Guides::render($guide),
            'pdfUrl' => Guides::pdfUrl($guide),
            'others' => collect(Guides::all())->filter(fn ($g, $k) => $g['public'] && $k !== $guide)->map(fn ($g) => ['title' => $g['title'], 'url' => route($g['route'])])->values(),
            'landingBotKey' => $settings->get('marketing.landing_bot_key'),
        ]);
    }
}
