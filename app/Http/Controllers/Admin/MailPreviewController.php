<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\MailCatalog;
use App\Support\MailHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Tous les e-mails de la plateforme avec des données d'exemple : on les regarde, puis on s'envoie un essai pour les voir dans une
 * vraie boîte de réception (et vérifier que l'envoi fonctionne). Réservé au super administrateur.
 */
class MailPreviewController extends Controller
{
    public function index(Request $request)
    {
        $key = MailCatalog::exists((string) $request->query('modele')) ? $request->query('modele') : 'welcome';

        // Les recherches DNS prennent quelques secondes : on garde le résultat dix minutes, et « Vérifier à nouveau » le recalcule.
        if ($request->boolean('verifier')) {
            Cache::forget('mail.health');
        }
        $health = Cache::remember('mail.health', 600, fn () => (new MailHealth)->checks());

        return view('admin.emails', [
            'catalog' => MailCatalog::all(),
            'groups' => MailCatalog::GROUPS,
            'current' => $key,
            'subject' => MailCatalog::subject($key),
            'driver' => (string) config('mail.default'),
            'from' => (string) config('mail.from.address'),
            'health' => $health,
            'healthSummary' => MailHealth::summary($health),
        ]);
    }

    /** Le HTML brut d'un e-mail, affiché dans le cadre de la page. */
    public function show(string $key): Response
    {
        abort_unless(MailCatalog::exists($key), 404);

        return response(MailCatalog::html($key), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Frame-Options' => 'SAMEORIGIN', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data: https:; style-src 'unsafe-inline'"]);
    }

    /** M'envoyer cet e-mail d'exemple, à l'adresse de mon compte. */
    public function send(Request $request, string $key): RedirectResponse
    {
        abort_unless(MailCatalog::exists($key), 404);

        $to = $request->user()->email;

        try {
            Mail::html(MailCatalog::html($key), fn ($message) => $message->to($to)->subject('[Aperçu] '.MailCatalog::subject($key)));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', "L'envoi a échoué : {$e->getMessage()}");
        }

        return back()->with('status', "Aperçu envoyé à {$to}. Ouvrez-le dans votre boîte : c'est exactement ce que verra le destinataire.");
    }
}
