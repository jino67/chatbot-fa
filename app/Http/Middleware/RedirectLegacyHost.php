<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Changement de nom de domaine sans casser personne. Les anciens noms (PLATFORM_LEGACY_HOSTS, séparés par des virgules) pointent
 * vers le même site : les pages que l'on ouvre dans un navigateur sont redirigées (301) vers l'adresse officielle (APP_URL),
 * pour que les anciens liens, QR codes imprimés et favoris continuent de marcher. Ce qui est appelé par des programmes ou déjà
 * installé continue d'être servi sur l'ancien nom : le widget et l'API des sites de vos clients, les webhooks de Meta et de Twilio,
 * le service worker et le manifeste des applications déjà installées. Voir docs/DOMAINE.md.
 */
class RedirectLegacyHost
{
    /** Les chemins qui ne sont jamais redirigés. */
    public const SERVED_EVERYWHERE = ['widget/*', 'api/*', 'webhooks/*', 'media/*', 'a/e', 'sw.js', 'manifest.webmanifest', 'icon-*', 'badge-*', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $legacy = self::legacyHosts();
        $host = self::bare($request->getHost());

        if ($legacy === [] || ! in_array($host, $legacy, true) || ! in_array($request->method(), ['GET', 'HEAD'], true) || $request->is(...self::SERVED_EVERYWHERE)) {
            return $next($request);
        }

        $target = rtrim((string) config('app.url'), '/');
        $official = self::bare((string) parse_url($target, PHP_URL_HOST));

        // Sans adresse officielle différente (ou en local), on ne redirige rien : jamais de boucle.
        if ($official === '' || $official === $host || in_array($official, ['localhost', '127.0.0.1'], true)) {
            return $next($request);
        }

        return redirect()->away($target.$request->getRequestUri(), 301);
    }

    /** @return list<string> les anciens noms de domaine, sans « www. » */
    public static function legacyHosts(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($host) => self::bare((string) $host),
            (array) config('platform.legacy_hosts', []),
        ))));
    }

    private static function bare(string $host): string
    {
        return preg_replace('/^www\./', '', mb_strtolower(trim($host)));
    }
}
