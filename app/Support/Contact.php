<?php

namespace App\Support;

use App\Services\PlatformSettings;

/**
 * Les moyens de joindre l'équipe Kouma depuis l'application : e-mail et WhatsApp (les Paramètres de la plateforme),
 * et le chat web (l'assistant de la page d'accueil). Chaque lien emporte le contexte (espace, compte, page) pour que
 * l'équipe sache tout de suite de qui et de quoi il s'agit. Un moyen non configuré n'est simplement pas proposé.
 */
final class Contact
{
    public static function email(): string
    {
        return trim((string) app(PlatformSettings::class)->brand()['email']);
    }

    /** Numéro WhatsApp de l'équipe, en chiffres (format wa.me), ou une chaîne vide. */
    public static function whatsapp(): string
    {
        return (string) app(PlatformSettings::class)->brand()['whatsapp'];
    }

    /** Clé publique de l'assistant de la page d'accueil : le chat web de Kouma, s'il existe. */
    public static function chatKey(): ?string
    {
        $key = app(PlatformSettings::class)->get('marketing.landing_bot_key');

        return $key ? (string) $key : null;
    }

    /** Qui écrit et depuis où, en trois lignes. */
    public static function context(): string
    {
        $user = auth()->user();
        $workspace = $user?->currentWorkspace();
        $lines = [];

        if ($workspace) {
            $lines[] = 'Espace : '.$workspace->name;
        }
        if ($user) {
            $lines[] = 'Compte : '.$user->email;
        }
        $lines[] = 'Page : /'.ltrim(request()->path(), '/');

        return implode("\n", $lines);
    }

    public static function mailto(string $subject, string $message = ''): ?string
    {
        $email = self::email();
        if ($email === '') {
            return null;
        }

        $body = "Bonjour,\n\n".$message."\n\n---\n".self::context();

        return 'mailto:'.$email.'?subject='.rawurlencode($subject).'&body='.rawurlencode($body);
    }

    public static function whatsappLink(string $message = ''): ?string
    {
        $number = self::whatsapp();
        if ($number === '') {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode("Bonjour Kouma, ".$message."\n\n".self::context());
    }
}
