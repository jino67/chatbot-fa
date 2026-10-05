<?php

namespace App\Support;

use App\Services\PlatformSettings;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Le nom qui s'affiche à côté de l'adresse d'expédition (« Kouma » dans la boîte de réception). Un .env mal écrit peut laisser
 * « ${APP_NAME} » tel quel (la variable n'est pas remplacée) : le destinataire voit alors un faux nom, et les filtres
 * antispam s'en méfient. On le remplace par le nom de la marque, sans toucher à un nom que l'équipe a choisi.
 */
final class MailSender
{
    public static function isPlaceholder(?string $name): bool
    {
        $name = trim((string) $name);

        return $name === '' || str_contains($name, '${') || str_contains($name, '{{') || in_array(mb_strtolower($name), ['example', 'laravel', 'null'], true);
    }

    /** Le nom d'expéditeur à afficher : celui du .env s'il est sain, sinon le nom de la marque. */
    public static function name(?string $configured = null): string
    {
        $configured ??= (string) config('mail.from.name');

        if (! self::isPlaceholder($configured)) {
            return trim($configured);
        }

        try {
            $brand = trim((string) (app(PlatformSettings::class)->brand()['name'] ?? ''));
        } catch (\Throwable) {
            $brand = '';
        }

        return $brand !== '' ? $brand : (string) config('brand.name', 'Kouma');
    }

    /** Corrige l'expéditeur d'un message prêt à partir (appelé à chaque envoi). */
    public static function fix(Email $message): void
    {
        $from = $message->getFrom()[0] ?? null;

        if ($from && self::isPlaceholder($from->getName())) {
            $message->from(new Address($from->getAddress(), self::name('')));
        }
    }
}
