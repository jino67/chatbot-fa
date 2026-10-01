/*
 * Service worker : il rend l'application installable, affiche une page claire quand le réseau manque, et reçoit les
 * notifications (Web Push) même quand l'application est fermée. Il ne garde aucune page en cache : les données d'un
 * compte ne sont jamais stockées sur l'appareil.
 */
const CACHE = 'kouma-shell-v1';
const SHELL = ['/offline.html', '/icon-192.png', '/favicon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Seules les navigations de notre propre site ont un repli hors ligne ; l'API et les webhooks passent toujours au réseau.
    if (request.mode !== 'navigate' || url.origin !== self.location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/webhooks/')) {
        return;
    }

    event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
});

/* ------------------------------------------------------------------------------------------------
   Notifications
   ------------------------------------------------------------------------------------------------ */

// Le compteur sur l'icône de l'application installée (Badging API) : le serveur envoie le nombre de notifications non lues.
async function setBadge(count) {
    try {
        if (typeof count !== 'number' || !self.navigator || !('setAppBadge' in self.navigator)) return;
        if (count > 0) await self.navigator.setAppBadge(count);
        else await self.navigator.clearAppBadge();
    } catch (error) { /* appareil sans compteur : sans importance */ }
}

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = { title: 'Nouvelle notification', body: event.data ? event.data.text() : '' };
    }

    const options = {
        body: data.body || '',
        icon: '/icon-192.png',
        badge: '/badge-96.png',
        tag: data.tag || undefined,
        // Un message de même tag remplace le précédent sur l'écran ; renotify fait quand même sonner l'appareil.
        renotify: !!data.tag,
        lang: 'fr',
        data: { id: data.id || null, url: data.url || '/notifications' },
    };

    // Les navigateurs exigent qu'un message reçu affiche toujours une notification (userVisibleOnly).
    event.waitUntil((async () => {
        await self.registration.showNotification(data.title || 'Kouma', options);
        await setBadge(data.badge);

        // Les fenêtres ouvertes mettent à jour leur cloche sans attendre.
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        windows.forEach((client) => client.postMessage({ type: 'notification', count: data.badge, id: data.id }));
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const info = event.notification.data || {};
    // Passer par /notifications/{id}/ouvrir marque la notification comme lue avant d'aller à sa page.
    const path = info.id ? '/notifications/' + info.id + '/ouvrir' : (info.url || '/notifications');
    const target = new URL(path, self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        for (const client of windows) {
            if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                if ('navigate' in client) await client.navigate(target).catch(() => null);
                return client.focus();
            }
        }

        return self.clients.openWindow(target);
    })());
});
