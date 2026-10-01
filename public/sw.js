/*
 * Service worker minimal : il rend l'application installable et affiche une page claire quand le réseau manque.
 * Il ne garde aucune page en cache : les données d'un compte ne sont jamais stockées sur l'appareil.
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
