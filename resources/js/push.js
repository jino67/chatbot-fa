/* -------------------------------------------------------------------------------------------------
   Notifications : abonnement de l'appareil (Web Push), compteur sur l'icône de l'application (Badging API), rappels
   d'activation, cloche du centre de notifications.

   Rien ne s'active sans un geste de la personne (le bouton « Activer les notifications ») : les navigateurs l'exigent
   et c'est aussi la bonne façon de demander. Une fois la permission donnée, l'abonnement se maintient tout seul.
   La configuration vient de la balise <meta name="kouma-push"> posée par le serveur sur les pages connectées.
   ------------------------------------------------------------------------------------------------- */

const REMINDER_KEY = 'kouma-push-reminder';
const SYNC_KEY = 'kouma-push-sync';
const ENDPOINT_KEY = 'kouma-push-endpoint';

function store(key, value) {
    try {
        if (value === undefined) return JSON.parse(localStorage.getItem(key) || 'null');
        if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, JSON.stringify(value));
    } catch (error) { /* stockage bloqué : le rappel reviendra à chaque visite */ }

    return null;
}

function keyToBytes(base64) {
    const padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

function bytesToKey(buffer) {
    if (!buffer) return '';

    return btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

const isIos = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
const canNotify = () => 'serviceWorker' in navigator && 'Notification' in window && 'PushManager' in window;

export function initPush() {
    const meta = document.querySelector('meta[name="kouma-push"]');
    if (!meta) return;

    let cfg;
    try { cfg = JSON.parse(meta.content); } catch (error) { return; }

    const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const call = (url, method, body) => fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: body ? JSON.stringify(body) : undefined,
    });

    const registration = () => navigator.serviceWorker.ready;

    /* ---------- Compteur sur l'icône de l'application ---------- */
    function setBadge(count) {
        try {
            if (!('setAppBadge' in navigator)) return;
            if (count > 0) navigator.setAppBadge(count).catch(() => {}); else navigator.clearAppBadge().catch(() => {});
        } catch (error) { /* appareil sans compteur */ }
    }

    /* ---------- Abonnement ---------- */
    async function subscription() {
        const reg = await registration();

        return reg.pushManager.getSubscription();
    }

    function payload(sub) {
        const json = sub.toJSON();

        return { endpoint: json.endpoint, keys: json.keys, standalone: isStandalone() };
    }

    // S'abonne (ou renouvelle l'abonnement si la clé de la plateforme a changé) et le signale au serveur.
    async function subscribe() {
        const reg = await registration();
        let sub = await reg.pushManager.getSubscription();

        if (sub && bytesToKey(sub.options && sub.options.applicationServerKey) !== cfg.key) {
            await sub.unsubscribe();
            sub = null;
        }
        if (!sub) {
            sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(cfg.key) });
        }

        const response = await call(cfg.subscribe, 'POST', payload(sub));
        if (!response.ok) throw new Error('subscribe ' + response.status);

        store(ENDPOINT_KEY, sub.endpoint);
        store(SYNC_KEY, { user: cfg.user, at: Date.now() });

        return sub;
    }

    // À chaque ouverture : si la permission est donnée, on s'assure que l'abonnement existe et appartient bien à la personne connectée.
    async function sync() {
        if (!canNotify() || Notification.permission !== 'granted') return;

        const last = store(SYNC_KEY);
        const fresh = last && last.user === cfg.user && Date.now() - last.at < 24 * 3600 * 1000;

        try {
            const sub = await subscription();
            if (sub && fresh && bytesToKey(sub.options && sub.options.applicationServerKey) === cfg.key) return;
            await subscribe();
        } catch (error) { /* réessai à la prochaine ouverture */ }
    }

    /* ---------- Interface publique ---------- */
    const api = {
        cfg,
        isIos: isIos(),
        standalone: isStandalone(),
        setBadge,

        // unsupported, ios-install, denied, default, granted (permission donnée, pas encore d'abonnement), subscribed
        async state() {
            if (isIos() && !isStandalone()) return 'ios-install';
            if (!canNotify()) return 'unsupported';
            if (Notification.permission === 'denied') return 'denied';
            if (Notification.permission === 'default') return 'default';

            try { return (await subscription()) ? 'subscribed' : 'granted'; } catch (error) { return 'granted'; }
        },

        // À appeler depuis un clic : c'est ce geste qui autorise le navigateur à poser la question.
        async enable() {
            if (!canNotify()) return { ok: false, state: 'unsupported' };

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') return { ok: false, state: permission === 'denied' ? 'denied' : 'default' };

            try {
                await subscribe();
            } catch (error) {
                return { ok: false, state: 'error' };
            }

            return { ok: true, state: 'subscribed' };
        },

        // Désactive les notifications de cet appareil (la permission du navigateur reste accordée : on peut réactiver d'un clic).
        async disable() {
            const sub = await subscription();
            if (sub) {
                await call(cfg.unsubscribe, 'DELETE', { endpoint: sub.endpoint }).catch(() => {});
                await sub.unsubscribe().catch(() => {});
            }
            store(ENDPOINT_KEY, null);
            store(SYNC_KEY, null);

            return 'granted';
        },

        async test() {
            const response = await call(cfg.test, 'POST');
            const data = await response.json().catch(() => ({}));

            return { ok: response.ok && data.sent, message: data.message || 'Essai impossible pour le moment.' };
        },

        /* Rappels : « Plus tard » repousse, trop de refus arrête les rappels (la personne garde le réglage dans son profil). */
        reminderDue() {
            const saved = store(REMINDER_KEY) || {};

            return !saved.never && (!saved.until || saved.until < Date.now());
        },

        snooze(days) {
            const saved = store(REMINDER_KEY) || {};
            saved.count = (saved.count || 0) + 1;
            saved.until = Date.now() + days * 86400000;
            if (saved.count >= cfg.reminderMax) saved.never = true;
            store(REMINDER_KEY, saved);
        },

        forget() { store(REMINDER_KEY, null); },
    };

    window.koumaPush = api;
    window.dispatchEvent(new Event('kouma-push-ready'));

    setBadge(cfg.unread);
    sync();

    // Une notification arrive pendant que l'application est ouverte : compteur et cloche suivent aussitôt.
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'notification') {
                window.dispatchEvent(new CustomEvent('kouma-notification', { detail: { count: event.data.count } }));
            }
        });
    }

    // Déconnexion : cet appareil ne doit plus recevoir les notifications du compte qu'on quitte (téléphone partagé).
    document.addEventListener('submit', (event) => {
        const action = event.target && event.target.getAttribute && event.target.getAttribute('action');
        if (!action || !/\/logout$/.test(action)) return;

        const endpoint = store(ENDPOINT_KEY);
        if (endpoint) {
            fetch(cfg.unsubscribe, {
                method: 'DELETE', keepalive: true, credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ endpoint }),
            }).catch(() => {});
        }
        store(SYNC_KEY, null);
        setBadge(0);
    }, true);
}

/* ---------- Composants Alpine ---------- */

// Carte d'activation des notifications (tableau de bord, préférences) : une phrase et un geste selon l'état de l'appareil.
window.pushCard = (options = {}) => ({
    state: 'loading',
    busy: false,
    message: '',
    hidden: false,
    reminder: !!options.reminder,

    async init() {
        await this.refresh();
        window.addEventListener('focus', () => this.refresh());
    },

    async refresh() {
        // Alpine peut démarrer avant initPush() : on attend (3 secondes au plus) que la configuration soit prête.
        if (!window.koumaPush) {
            await new Promise((resolve) => {
                window.addEventListener('kouma-push-ready', resolve, { once: true });
                setTimeout(resolve, 3000);
            });
        }
        if (!window.koumaPush) { this.state = 'unsupported'; return; }

        this.state = await window.koumaPush.state();
        // Le rappel n'apparaît que s'il y a quelque chose à faire, et pas trop souvent.
        if (this.reminder) this.hidden = ['subscribed', 'unsupported', 'loading'].includes(this.state) || !window.koumaPush.reminderDue();
    },

    get platform() {
        if (!window.koumaPush) return 'desktop';
        if (window.koumaPush.isIos) return 'ios';

        return /Android/.test(navigator.userAgent) ? 'android' : 'desktop';
    },

    async enable() {
        this.busy = true;
        this.message = '';
        const result = await window.koumaPush.enable();
        this.busy = false;
        this.state = result.state === 'error' ? await window.koumaPush.state() : result.state;
        if (result.ok) this.message = 'C\'est fait : les notifications sont activées sur cet appareil.';
        else if (result.state === 'error') this.message = 'L\'activation n\'a pas abouti. Réessayez dans un instant.';
    },

    async disable() {
        this.busy = true;
        this.state = await window.koumaPush.disable();
        this.busy = false;
        this.message = 'Notifications désactivées sur cet appareil. Vous pouvez les réactiver à tout moment.';
    },

    async test() {
        this.busy = true;
        const result = await window.koumaPush.test();
        this.busy = false;
        this.message = result.message;
    },

    later(denied) {
        window.koumaPush.snooze(denied ? window.koumaPush.cfg.deniedSnoozeDays : window.koumaPush.cfg.snoozeDays);
        this.hidden = true;
    },
});

// Cloche du centre de notifications : compteur, aperçu des dernières notifications, mise à jour sans recharger la page.
window.notifBell = (summaryUrl, initial) => ({
    unread: initial,
    open: false,
    items: [],
    loaded: false,

    init() {
        window.addEventListener('kouma-notification', (event) => { this.unread = event.detail.count; if (this.open) this.load(); });
        // Une relève par minute, et seulement si la cloche est visible (il y en a une pour le bureau, une pour le mobile) :
        // quand la liaison est coupée (DNS, changement de réseau), on espace les essais au lieu de remplir la console.
        let failures = 0;
        const tick = async () => {
            if (!document.hidden && navigator.onLine !== false && this.$el.offsetParent !== null) {
                failures = (await this.load(true)) ? 0 : failures + 1;
            }
            setTimeout(tick, Math.min(600000, 60000 * 2 ** Math.min(failures, 4)));
        };
        setTimeout(tick, 60000);
    },

    async toggle() {
        this.open = !this.open;
        if (this.open) await this.load();
    },

    async load(quiet = false) {
        try {
            const response = await fetch(summaryUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return false;
            const data = await response.json();
            this.unread = data.unread;
            if (!quiet || this.open) this.items = data.items;
            this.loaded = true;
            if (window.koumaPush) window.koumaPush.setBadge(data.unread);

            return true;
        } catch (error) { /* hors connexion : le compteur reste celui de la page */ return false; }
    },
});
