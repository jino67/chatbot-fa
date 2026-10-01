<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Notify\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Le centre de notifications d'une personne : la liste, la cloche (résumé en JSON), l'ouverture d'une notification
 * (qui la marque comme lue puis mène à sa page) et les préférences. Chaque lecture part de l'utilisateur connecté.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $filter = $request->query('filtre') === 'non-lues' ? 'non-lues' : 'toutes';

        $notifications = $user->appNotifications()
            ->when($filter === 'non-lues', fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('notifications.index', [
            'notifications' => $notifications,
            'filter' => $filter,
            'unread' => $user->unreadNotificationCount(),
        ]);
    }

    /** Ce que la cloche affiche : le compteur et les huit dernières notifications. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotificationCount(),
            'items' => $user->appNotifications()->orderByDesc('id')->limit(8)->get()->map(fn (AppNotification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => (string) $n->body,
                'label' => $n->categoryLabel(),
                'category' => $n->category,
                'read' => $n->isRead(),
                'ago' => $n->created_at->locale('fr')->diffForHumans(null, true),
                'url' => route('notifications.open', $n->id),
            ])->all(),
        ]);
    }

    /** Ouvre une notification : elle est marquée comme lue, puis on va à sa page. Sert aussi au clic sur la notification du téléphone. */
    public function open(Request $request, int $notification, Notifier $notifier): RedirectResponse
    {
        $item = $request->user()->appNotifications()->whereKey($notification)->firstOrFail();
        $notifier->markRead($request->user(), [$item->id]);

        $target = $item->url ?: route('notifications.index');

        return str_starts_with($target, 'https://') ? redirect()->away($target) : redirect($target);
    }

    public function read(Request $request, int $notification, Notifier $notifier): JsonResponse
    {
        $item = $request->user()->appNotifications()->whereKey($notification)->firstOrFail();
        $notifier->markRead($request->user(), [$item->id]);

        return response()->json(['unread' => $request->user()->unreadNotificationCount()]);
    }

    public function readAll(Request $request, Notifier $notifier): RedirectResponse|JsonResponse
    {
        $notifier->markRead($request->user());

        return $request->expectsJson()
            ? response()->json(['unread' => 0])
            : back()->with('status', 'Toutes les notifications sont marquées comme lues.');
    }

    public function preferences(Request $request)
    {
        $user = $request->user();

        return view('notifications.preferences', [
            'prefs' => $user->notificationPrefs(),
            'categories' => config('notifications.categories'),
            'devices' => $user->pushSubscriptions()->orderByDesc('id')->get(),
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $categories = array_keys(config('notifications.categories'));

        $data = $request->validate([
            'push' => ['nullable', 'boolean'],
            'cats' => ['nullable', 'array'],
            'cats.*' => ['boolean'],
            'quiet_on' => ['nullable', 'boolean'],
            'quiet_from' => ['nullable', 'integer', 'between:0,23'],
            'quiet_to' => ['nullable', 'integer', 'between:0,23'],
        ]);

        $cats = [];
        foreach ($categories as $key) {
            // Une case décochée n'est pas envoyée par le navigateur : l'absence veut dire « non ». Les catégories verrouillées restent à oui.
            $cats[$key] = (bool) config("notifications.categories.{$key}.locked") || (bool) ($data['cats'][$key] ?? false);
        }

        $request->user()->forceFill(['notification_prefs' => [
            'push' => $request->boolean('push'),
            'cats' => $cats,
            'quiet' => [
                'on' => $request->boolean('quiet_on'),
                'from' => (int) ($data['quiet_from'] ?? config('notifications.quiet_hours.from')),
                'to' => (int) ($data['quiet_to'] ?? config('notifications.quiet_hours.to')),
            ],
        ]])->save();

        return back()->with('status', 'Vos préférences de notifications sont enregistrées.');
    }
}
