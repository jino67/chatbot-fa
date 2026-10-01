/* -------------------------------------------------------------------------------------------------
   Mesure d'audience maison : pages vues, clics, défilement, temps passé, formulaires, erreurs, vitesse.

   Respect de la vie privée :
   - rien ne part sans la balise <meta name="kouma-analytics"> (posée par le serveur quand la mesure est active) ;
   - « Ne pas suivre » (DNT), « Global Privacy Control » et le refus du visiteur (cookie _ko) coupent tout ;
   - aucun texte saisi n'est lu : on note le libellé d'un bouton ou d'un lien, jamais le contenu d'un champ ;
   - la console d'administration et les pages de démonstration ne sont pas mesurées.

   Les lots partent avec navigator.sendBeacon vers /a/e (voir App\Services\Analytics\Tracker).
   ------------------------------------------------------------------------------------------------- */

const MAX_EVENTS_PER_PAGE = 150;
const SESSION_IDLE_MS = 30 * 60 * 1000;

function readCookie(name) {
    const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

function writeCookie(name, value, days) {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    const expires = days ? `; Max-Age=${days * 86400}` : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; Path=/; SameSite=Lax${expires}${secure}`;
}

function randomKey(length = 22) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    const bytes = new Uint8Array(length);
    (window.crypto || window.msCrypto).getRandomValues(bytes);

    return Array.from(bytes, (b) => alphabet[b % alphabet.length]).join('');
}

function optedOut() {
    try {
        return readCookie('_ko') === '1' || localStorage.getItem('kouma-optout') === '1';
    } catch (e) {
        return readCookie('_ko') === '1';
    }
}

/** Le visiteur choisit de ne plus être mesuré (ou de l'être de nouveau) : page Confidentialité. */
window.koumaOptOut = function (value) {
    try { value ? localStorage.setItem('kouma-optout', '1') : localStorage.removeItem('kouma-optout'); } catch (e) { /* stockage bloqué */ }
    if (value) writeCookie('_ko', '1', 395); else writeCookie('_ko', '', -1);
    window.dispatchEvent(new CustomEvent('kouma-optout', { detail: !!value }));
};
window.koumaOptedOut = optedOut;

export function initAnalytics() {
    const meta = document.querySelector('meta[name="kouma-analytics"]');
    if (!meta || !window.fetch || !navigator.sendBeacon) return;
    if (/^\/(admin|demo)(\/|$)/.test(location.pathname)) return;
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.msDoNotTrack === '1' || navigator.globalPrivacyControl === true) return;
    if (optedOut()) return;

    const endpoint = meta.content;
    const now = () => Date.now();

    /* ---------- Identité : un visiteur (13 mois), une visite (30 minutes sans geste) ---------- */
    let visitor = readCookie('_kv');
    if (!visitor || !/^[A-Za-z0-9]{16,32}$/.test(visitor)) {
        visitor = randomKey();
    }
    writeCookie('_kv', visitor, 395);

    let lastSeen = 0;
    try { lastSeen = Number(localStorage.getItem('kouma-last')) || 0; } catch (e) { /* ignore */ }

    let session = readCookie('_ks');
    const newSession = !session || !/^[A-Za-z0-9]{16,32}$/.test(session) || now() - lastSeen > SESSION_IDLE_MS;
    if (newSession) session = randomKey();
    writeCookie('_ks', session, 0);

    function touch() {
        try { localStorage.setItem('kouma-last', String(now())); } catch (e) { /* ignore */ }
    }
    touch();

    /* ---------- File d'envoi ---------- */
    const queue = [];
    let metaSent = !newSession;
    let total = 0;
    let timer = null;

    function sessionMeta() {
        const params = new URLSearchParams(location.search);
        const external = document.referrer && new URL(document.referrer).host !== location.host ? document.referrer : '';

        return {
            r: external,
            u: { s: params.get('utm_source') || '', m: params.get('utm_medium') || '', c: params.get('utm_campaign') || '' },
            tz: (Intl.DateTimeFormat().resolvedOptions() || {}).timeZone || '',
            l: navigator.language || '',
            pwa: window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true,
        };
    }

    function flush() {
        clearTimeout(timer);
        timer = null;
        if (!queue.length) return;

        const payload = { v: visitor, s: session, e: queue.splice(0, 40) };
        if (!metaSent) {
            payload.m = sessionMeta();
            metaSent = true;
        }

        const body = JSON.stringify(payload);
        try {
            if (!navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }))) throw new Error('beacon');
        } catch (e) {
            fetch(endpoint, { method: 'POST', body, keepalive: true, credentials: 'same-origin' }).catch(() => {});
        }
        touch();
        if (queue.length) flush();
    }

    function track(type, name, extra = {}) {
        if (total >= MAX_EVENTS_PER_PAGE) return;
        total++;
        queue.push({ t: type, n: name ? String(name).slice(0, 140) : '', p: location.pathname, ...extra });
        if (!timer) timer = setTimeout(flush, 4000);
    }

    /* ---------- Page vue ---------- */
    track('pageview', document.title.slice(0, 100));
    flush();

    /* ---------- Clics : libellé, destination, appels à l'action, contacts ---------- */
    const CTA = '.btn, .btn-primary, .btn-accent, .btn-outline, [data-cta]';
    const recent = [];

    function label(el) {
        const text = el.getAttribute('data-track') || el.getAttribute('aria-label')
            || (el.tagName === 'INPUT' ? el.value : (el.innerText || el.textContent)) || el.getAttribute('title')
            || (el.querySelector('img') ? el.querySelector('img').alt : '') || '';

        return text.replace(/\s+/g, ' ').trim().slice(0, 80);
    }

    function section(el) {
        const holder = el.closest('[data-section], [data-track-view], section[id], header, footer, nav, aside');
        if (!holder) return '';

        return holder.getAttribute('data-section') || holder.getAttribute('data-track-view') || holder.id || holder.tagName.toLowerCase();
    }

    document.addEventListener('click', (event) => {
        const el = event.target.closest('a, button, [role="button"], [data-track], summary, input[type="submit"]');
        if (!el || el.closest('[data-no-track]')) return;

        const text = label(el);
        const cta = el.matches(CTA);
        const holder = section(el);
        const props = holder ? { sec: holder } : {};
        const href = el.tagName === 'A' ? el.getAttribute('href') || '' : '';

        // Clics répétés au même endroit : un élément qui ne répond pas (signe de frustration).
        const point = { t: now(), x: event.clientX, y: event.clientY };
        recent.push(point);
        while (recent.length && point.t - recent[0].t > 1200) recent.shift();
        const burst = recent.filter((p) => Math.abs(p.x - point.x) < 40 && Math.abs(p.y - point.y) < 40);
        if (burst.length >= 3) {
            track('rage', text || el.tagName.toLowerCase(), { o: props });
            recent.length = 0;
        }

        if (/^mailto:/i.test(href)) return track('contact', 'email', { c: cta, o: props });
        if (/^tel:/i.test(href)) return track('contact', 'telephone', { c: cta, o: props });

        let url = null;
        try { url = href ? new URL(href, location.href) : null; } catch (e) { /* lien illisible */ }

        if (url && /(^|\.)(wa\.me|whatsapp\.com)$/i.test(url.host)) return track('contact', 'whatsapp', { c: cta, g: url.href, o: props });
        if (url && url.host !== location.host) return track('outbound', text, { c: cta, g: url.href, o: props });

        if (url && /\.pdf$/i.test(url.pathname)) props.dl = 1;
        track('click', text, { c: cta, g: url ? url.pathname : '', o: props });
    }, true);

    /* ---------- Défilement : 25, 50, 75, 100 % ---------- */
    const marks = new Set();
    let scrollTimer = null;

    function onScroll() {
        scrollTimer = null;
        const root = document.documentElement;
        const percent = Math.round(((window.scrollY + window.innerHeight) / Math.max(root.scrollHeight, 1)) * 100);

        [25, 50, 75, 100].forEach((mark) => {
            if (percent >= mark - 1 && !marks.has(mark)) {
                marks.add(mark);
                track('scroll', null, { x: mark });
            }
        });
    }
    window.addEventListener('scroll', () => { if (!scrollTimer) scrollTimer = setTimeout(onScroll, 350); }, { passive: true });
    setTimeout(onScroll, 1500);

    /* ---------- Sections vues (data-track-view) ---------- */
    if ('IntersectionObserver' in window) {
        const timers = new WeakMap();
        const seen = new Set();
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const name = entry.target.getAttribute('data-track-view');
                if (entry.isIntersecting && !seen.has(name)) {
                    timers.set(entry.target, setTimeout(() => { seen.add(name); track('view', name); }, 1000));
                } else if (timers.has(entry.target)) {
                    clearTimeout(timers.get(entry.target));
                }
            });
        }, { threshold: 0.5 });
        document.querySelectorAll('[data-track-view]').forEach((el) => observer.observe(el));
    }

    /* ---------- Formulaires : début de saisie et envoi (jamais le contenu) ---------- */
    const started = new Set();
    const formName = (form) => form.getAttribute('data-track-form') || form.id || (form.getAttribute('action') || '').replace(/^https?:\/\/[^/]+/, '').split('?')[0] || 'formulaire';

    document.addEventListener('focusin', (event) => {
        const field = event.target.closest('input, textarea, select');
        const form = field && field.form;
        if (!form || form.closest('[data-no-track]') || started.has(form)) return;
        if (field.type === 'hidden') return;
        started.add(form);
        track('form_start', formName(form));
    });

    document.addEventListener('submit', (event) => {
        if (event.target.closest && event.target.closest('[data-no-track]')) return;
        track('form_submit', formName(event.target));
        flush();
    }, true);

    /* ---------- Erreurs JavaScript ---------- */
    let errors = 0;
    window.addEventListener('error', (event) => {
        if (errors++ >= 5 || !event.message || event.message === 'Script error.') return;
        track('error', event.message, { g: event.filename || '', o: { l: event.lineno || 0 } });
    });
    window.addEventListener('unhandledrejection', (event) => {
        if (errors++ >= 5) return;
        const reason = event.reason;
        track('error', 'Promesse rejetée : ' + String((reason && reason.message) || reason || '').slice(0, 100));
    });

    /* ---------- Temps passé réellement actif ---------- */
    let active = 0;
    let lastTick = now();
    let lastInput = now();
    ['mousemove', 'keydown', 'touchstart', 'scroll', 'click'].forEach((name) => window.addEventListener(name, () => { lastInput = now(); }, { passive: true }));
    setInterval(() => {
        const t = now();
        if (document.visibilityState === 'visible' && t - lastInput < 30000) active += Math.min(2, (t - lastTick) / 1000);
        lastTick = t;
    }, 1000);

    /* ---------- Vitesse perçue (Web Vitals) ---------- */
    const vitals = {};
    try {
        if ('PerformanceObserver' in window) {
            const watch = (type, callback, options = {}) => {
                try { new PerformanceObserver((list) => list.getEntries().forEach(callback)).observe({ type, buffered: true, ...options }); } catch (e) { /* mesure indisponible */ }
            };
            watch('largest-contentful-paint', (entry) => { vitals.LCP = entry.startTime; });
            watch('layout-shift', (entry) => { if (!entry.hadRecentInput) vitals.CLS = (vitals.CLS || 0) + entry.value; });
            watch('event', (entry) => { vitals.INP = Math.max(vitals.INP || 0, entry.duration); }, { durationThreshold: 40 });
            watch('paint', (entry) => { if (entry.name === 'first-contentful-paint') vitals.FCP = entry.startTime; });
        }
        const nav = performance.getEntriesByType('navigation')[0];
        if (nav && nav.responseStart) vitals.TTFB = nav.responseStart;
    } catch (e) { /* ignore */ }

    let vitalsSent = false;
    function sendVitals() {
        if (vitalsSent || !Object.keys(vitals).length) return;
        vitalsSent = true;
        Object.entries(vitals).forEach(([name, value]) => track('vital', name, { x: name === 'CLS' ? value * 1000 : value }));
    }

    /* ---------- Départ de la page : temps actif, vitesse, envoi final ---------- */
    let reported = 0;
    function leaving() {
        sendVitals();
        const seconds = Math.round(active - reported);
        if (seconds > 0) {
            reported += seconds;
            track('engage', null, { x: seconds });
        }
        flush();
    }
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') leaving(); });
    window.addEventListener('pagehide', leaving);

    /* ---------- Pour l'application : window.koumaTrack('nom', { clé: valeur }) ---------- */
    window.koumaTrack = (name, props) => track('click', name, { o: props || {} });
}
