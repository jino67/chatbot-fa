<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Notify\Notifier;
use App\Push\PushEndpoint;
use App\Push\WebPushCrypto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Les appareils de la personne connectée qui reçoivent des notifications : abonnement (depuis le navigateur), désabonnement,
 * essai, et retrait d'un appareil depuis la liste. Tout se lit à partir de l'utilisateur connecté, jamais d'un identifiant d'appareil
 * venu de la requête : un appareil ne peut pas être retiré ou lu par quelqu'un d'autre.
 */
class PushController extends Controller
{
    /** Au plus ce nombre d'appareils par personne : au-delà, les plus anciens sont oubliés. */
    private const MAX_DEVICES = 10;

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:40'],
            'standalone' => ['nullable', 'boolean'],
        ]);

        // L'adresse vient du navigateur : seuls les vrais services de notification sont acceptés (voir PushEndpoint).
        abort_unless(PushEndpoint::isAllowed($data['endpoint']), 422, 'Service de notification non pris en charge.');

        $point = WebPushCrypto::b64urlDecode($data['keys']['p256dh']);
        abort_unless(strlen($point) === 65 && $point[0] === "\x04" && strlen(WebPushCrypto::b64urlDecode($data['keys']['auth'])) === 16, 422, 'Clés d\'abonnement invalides.');

        $user = $request->user();
        $userAgent = mb_substr((string) $request->userAgent(), 0, 255);

        // Le même appareil réutilisé par une autre personne (téléphone partagé, nouvelle connexion) passe au compte courant.
        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashOf($data['endpoint'])],
            [
                'user_id' => $user->id,
                'workspace_id' => $user->isStaff() ? null : $user->workspace_id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'platform' => PushEndpoint::platform($userAgent),
                'user_agent' => $userAgent,
                'standalone' => (bool) ($data['standalone'] ?? false),
                'failures' => 0,
            ],
        );

        // Au-delà de la limite, les appareils les plus anciens sont oubliés (OFFSET sans LIMIT n'existe pas en SQL : on garde la liste des récents).
        $user->pushSubscriptions()->whereNotIn('id', $user->pushSubscriptions()->orderByDesc('id')->limit(self::MAX_DEVICES)->pluck('id'))->delete();

        return response()->json(['subscribed' => true, 'id' => $subscription->id, 'devices' => $user->pushSubscriptions()->count()]);
    }

    /** Le navigateur se désabonne : on oublie l'appareil (par son adresse, retrouvée parmi ceux de la personne). */
    public function unsubscribe(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);

        $request->user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))->delete();

        return response()->noContent();
    }

    /** Retire un appareil depuis la liste des préférences. */
    public function destroy(Request $request, int $subscription): RedirectResponse
    {
        $request->user()->pushSubscriptions()->whereKey($subscription)->delete();

        return back()->with('status', 'Appareil retiré : il ne recevra plus de notifications.');
    }

    /** Une notification d'essai sur les appareils de la personne, pour vérifier que tout fonctionne. */
    public function test(Request $request, Notifier $notifier): JsonResponse
    {
        $user = $request->user();

        if (! $user->pushSubscriptions()->exists()) {
            return response()->json(['sent' => false, 'message' => 'Aucun appareil n\'est encore activé : appuyez d\'abord sur « Activer les notifications ».'], 409);
        }

        $notification = $notifier->test($user);

        return response()->json([
            'sent' => $notification?->pushed_at !== null,
            'message' => $notification?->pushed_at !== null
                ? 'Notification envoyée : elle doit apparaître dans quelques secondes sur votre appareil.'
                : 'L\'envoi n\'a pas abouti. Si les notifications sont bloquées sur cet appareil, débloquez-les dans les réglages du navigateur.',
        ]);
    }
}
