/**
 * Mouvement du site. Chaque effet est optionnel : sans JavaScript, ou si le visiteur demande de reduire
 * les animations, la page reste complete (tous les messages visibles, prix affiches, compteurs a leur valeur).
 */

const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/* -------------------------------------------------------------------------------------------------
   Conversation animee. Sur l'appareil pliable : il se deplie, le client tape son message, l'assistant « ecrit »
   (points, la marque ouvre la bouche), et la boite de reception de la gerante recoit la commande a confirmer puis
   la demande d'une personne, avec l'alerte WhatsApp. La sequence se replie et se rejoue tant qu'elle est visible.
   Balisage : components/fold-demo.blade.php (ou chat-demo.blade.php, sans appareil ni boite de reception).
   ------------------------------------------------------------------------------------------------- */
function initChatDemo(root) {
    if (reduceMotion()) return;

    const body = root.querySelector('[data-chat-body]');
    const typed = root.querySelector('[data-chat-typed]');
    const placeholder = root.querySelector('[data-chat-placeholder]');
    const typing = root.querySelector('[data-chat-typing]');
    const toast = root.querySelector('[data-chat-toast]');
    const speaker = root.querySelector('[data-chat-speaker]');
    const items = Array.from(body?.querySelectorAll('[data-say]') ?? []);

    // Appareil pliable (grands ecrans) et boite de reception de la gerante.
    const fold = root.querySelector('[data-fold]');
    const foldable = Boolean(fold);
    const leadCards = new Map(Array.from(root.querySelectorAll('[data-lead-card]')).map((el) => [el.dataset.leadCard, el]));
    const leadCount = root.querySelector('[data-lead-count]');

    if (!body || !typing || !items.length) return;

    let visible = false;
    let wake = null;
    let run = 0;

    const untilVisible = () => (visible ? Promise.resolve() : new Promise((resolve) => { wake = resolve; }));

    // La sequence attend quand la conversation n'est pas a l'ecran : rien ne tourne dans le vide.
    new IntersectionObserver((entries) => {
        visible = entries[0].isIntersecting;
        if (visible && wake) {
            wake();
            wake = null;
        }
    }, { threshold: 0.3 }).observe(root);

    const scrollDown = () => body.scrollTo({ top: body.scrollHeight, behavior: 'smooth' });

    // Change l'etat de l'appareil sans transition (depart : replie, sans qu'on voie l'appareil se refermer au chargement).
    function foldInstant(state) {
        const parts = [fold, fold.querySelector('.fold-right')].filter(Boolean);
        parts.forEach((el) => { el.style.transition = 'none'; });
        fold.dataset.state = state;
        void fold.offsetWidth;
        parts.forEach((el) => { el.style.transition = ''; });
    }

    async function foldTo(state) {
        if (!foldable) return;
        fold.dataset.state = state;
        await sleep(1800);
    }

    function reset() {
        items.forEach((el) => {
            el.classList.add('demo-pending');
            el.classList.remove('demo-pop');
        });
        leadCards.forEach((card) => {
            card.classList.add('demo-pending');
            card.classList.remove('demo-pop');
        });
        if (leadCount) leadCount.textContent = '0';
        typing.classList.add('demo-pending');
        toast?.classList.remove('is-on');
        speaker?.classList.remove('is-speaking');
        if (typed) typed.textContent = '';
        if (placeholder) placeholder.hidden = false;
        body.scrollTop = 0;
    }

    // Une demande arrive dans la boite de reception : la carte apparait, le compteur rebondit, une onde part.
    function showLead(kind) {
        const card = leadCards.get(kind);
        if (!card) return;

        card.classList.remove('demo-pending');
        card.classList.add('demo-pop');

        if (leadCount) {
            const shown = Array.from(leadCards.values()).filter((c) => !c.classList.contains('demo-pending')).length;
            leadCount.textContent = String(shown);
            leadCount.animate([{ transform: 'scale(1.6)' }, { transform: 'scale(1)' }], { duration: 450, easing: 'cubic-bezier(0.2, 1.4, 0.3, 1)' });
        }

        if (kind === 'human') toast?.classList.add('is-on');

        const box = card.getBoundingClientRect();
        window.dispatchEvent(new CustomEvent('kouma:drop', { detail: { x: box.left + box.width / 2, y: box.top + box.height / 2 } }));
    }

    async function play(id) {
        reset();

        if (foldable) {
            // L'appareil est replie : l'ecran de couverture affiche la notification, puis il se deplie.
            await untilVisible();
            await sleep(1100);
            if (id !== run) return;
            await foldTo('open');
            await sleep(250);
        } else {
            await sleep(500);
        }

        for (const el of items) {
            await untilVisible();
            if (id !== run) return;

            if (el.dataset.say === 'user') {
                // Le client tape son message dans la barre de saisie, puis l'envoie.
                const text = (el.dataset.text || el.textContent).trim();
                if (placeholder) placeholder.hidden = true;
                typed?.classList.add('demo-caret');
                for (let i = 1; i <= text.length; i++) {
                    if (typed) typed.textContent = text.slice(0, i);
                    await sleep(24 + Math.random() * 34);
                    if (id !== run) return;
                }
                await sleep(260);
                if (typed) {
                    typed.textContent = '';
                    typed.classList.remove('demo-caret');
                }
                if (placeholder) placeholder.hidden = false;
            } else {
                // L'assistant « ecrit » : trois points, et la marque du logo ouvre la bouche.
                body.appendChild(typing);
                typing.classList.remove('demo-pending');
                scrollDown();
                speaker?.classList.add('is-speaking');
                await sleep(Number(el.dataset.typing) || 1000);
                if (id !== run) return;
                typing.classList.add('demo-pending');
                speaker?.classList.remove('is-speaking');
            }

            el.classList.remove('demo-pending');
            el.classList.add('demo-pop');
            scrollDown();

            // Une reponse de l'assistant lance une onde sur le fond (voir effects.js).
            if (el.dataset.say === 'bot') {
                const box = el.getBoundingClientRect();
                window.dispatchEvent(new CustomEvent('kouma:drop', { detail: { x: box.left + box.width * 0.35, y: box.top + box.height / 2 } }));
            }

            if (el.dataset.lead) {
                await sleep(500);
                showLead(el.dataset.lead);
            }

            await sleep(el.dataset.say === 'user' ? 420 : 1000);
        }

        if (!leadCards.size) toast?.classList.add('is-on');
        await sleep(5200);
        if (id !== run) return;

        if (foldable) {
            // L'appareil se replie, puis tout recommence.
            toast?.classList.remove('is-on');
            await foldTo('closed');
            reset();
            if (id === run) play(id);
            return;
        }

        body.classList.add('demo-fade');
        body.style.opacity = '0';
        toast?.classList.remove('is-on');
        await sleep(450);
        reset();
        body.style.opacity = '1';
        await sleep(300);
        if (id === run) play(id);
    }

    if (foldable) foldInstant('closed');

    run += 1;
    play(run);
}
/* -------------------------------------------------------------------------------------------------
   Parallaxe : les formes marquees .parallax suivent doucement le pointeur (souris uniquement).
   ------------------------------------------------------------------------------------------------- */
function initParallax() {
    if (reduceMotion() || !window.matchMedia('(hover: hover)').matches) return;

    document.querySelectorAll('[data-parallax]').forEach((area) => {
        let frame = 0;

        area.addEventListener('pointermove', (event) => {
            const box = area.getBoundingClientRect();
            const x = ((event.clientX - box.left) / box.width - 0.5) * 2;
            const y = ((event.clientY - box.top) / box.height - 0.5) * 2;

            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                area.style.setProperty('--px', x.toFixed(3));
                area.style.setProperty('--py', y.toFixed(3));
            });
        });

        area.addEventListener('pointerleave', () => {
            area.style.setProperty('--px', '0');
            area.style.setProperty('--py', '0');
        });
    });
}

/* -------------------------------------------------------------------------------------------------
   Devises : le choix change les prix sans recharger la page (les liens ?devise=XXX restent le repli).
   Chaque prix porte ses versions dans data-prices ; l'ancien prix monte, le nouveau arrive du bas.
   ------------------------------------------------------------------------------------------------- */
function initCurrencySwitchers() {
    const switchers = document.querySelectorAll('[data-currency-switcher]');
    if (!switchers.length) return;

    function swap(el, text) {
        if (el.textContent.trim() === text) return;
        if (reduceMotion()) {
            el.textContent = text;
            return;
        }
        el.classList.remove('price-in');
        el.classList.add('price-out');
        setTimeout(() => {
            el.textContent = text;
            el.classList.remove('price-out');
            el.classList.add('price-in');
        }, 160);
    }

    switchers.forEach((switcher) => {
        switcher.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-currency]');
            if (!link) return;

            event.preventDefault();
            const code = link.dataset.currency;

            document.querySelectorAll('[data-currency-label]').forEach((label) => { label.textContent = link.dataset.label || code; });

            document.querySelectorAll('[data-currency-switcher] a[data-currency]').forEach((a) => {
                const on = a.dataset.currency === code;
                a.dataset.on = String(on);
                if (on) a.setAttribute('aria-current', 'true');
                else a.removeAttribute('aria-current');
            });

            document.querySelectorAll('[data-prices]').forEach((el) => {
                try {
                    const prices = JSON.parse(el.dataset.prices);
                    if (prices[code]) swap(el, prices[code]);
                } catch (e) {
                    /* prix illisible : on laisse l'affichage tel quel */
                }
            });

            // Memorise le choix cote serveur (session, ou espace du client connecte).
            fetch(`/devise/${code}`, { credentials: 'same-origin' }).catch(() => {});
        });
    });
}

/* -------------------------------------------------------------------------------------------------
   Compteurs : un chiffre [data-count] monte de 0 a sa valeur quand il arrive a l'ecran.
   ------------------------------------------------------------------------------------------------- */
function initCounters() {
    const counters = document.querySelectorAll('[data-count]');
    if (!counters.length || reduceMotion()) return;

    const format = new Intl.NumberFormat('fr-FR');

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            observer.unobserve(entry.target);

            const el = entry.target;
            const target = Number(el.dataset.count);
            if (!Number.isFinite(target) || target === 0) return;

            const start = performance.now();
            const duration = 900;
            const tick = (now) => {
                const t = Math.min(1, (now - start) / duration);
                const eased = 1 - Math.pow(1 - t, 3);
                el.textContent = format.format(Math.round(target * eased));
                if (t < 1) requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        });
    }, { threshold: 0.6 });

    counters.forEach((el) => observer.observe(el));
}

/* -------------------------------------------------------------------------------------------------
   Navigation du site public : goutte qui suit le survol et la section lue, avancement de la page,
   barre qui se replie a la descente, « o » du logo qui s'ouvre. Balisage : components/site-nav.blade.php
   ------------------------------------------------------------------------------------------------- */
function initSiteNav() {
    const header = document.querySelector('[data-site-nav]');
    if (!header) return;

    const bar = header.querySelector('[data-nav-bar]');
    const nav = header.querySelector('[data-nav-links]');
    const blob = header.querySelector('[data-nav-blob]');
    const progress = header.querySelector('[data-nav-progress]');
    const logo = header.querySelector('.logo');
    const items = Array.from(header.querySelectorAll('[data-nav-link]'));
    let active = null;

    const moveBlob = (item) => {
        if (!blob) return;
        if (!item) {
            blob.style.opacity = '0';
            return;
        }
        blob.style.opacity = '1';
        blob.style.width = `${item.offsetWidth}px`;
        blob.style.transform = `translateX(${item.offsetLeft}px)`;
    };

    items.forEach((item) => {
        item.addEventListener('mouseenter', () => moveBlob(item));
        item.addEventListener('focus', () => moveBlob(item));
    });
    nav?.addEventListener('mouseleave', () => moveBlob(active));
    nav?.addEventListener('focusout', () => moveBlob(active));

    // La section a l'ecran devient le lien actif.
    // Seuls les liens d'ancre (#section) désignent une section de la page ; « Développeurs » est une autre page.
    const targets = items
        .map((item) => {
            const href = item.getAttribute('href') ?? '';
            const hash = href.includes('#') ? href.slice(href.indexOf('#')) : '';

            return hash.length > 1 && /^#[\w-]+$/.test(hash) && (href.startsWith('#') || new URL(href, window.location.href).pathname === window.location.pathname)
                ? document.querySelector(hash)
                : null;
        })
        .filter(Boolean);
    if (targets.length) {
        const spy = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                active = items.find((item) => (item.getAttribute('href') ?? '').endsWith(`#${entry.target.id}`)) ?? null;
                items.forEach((item) => { item.dataset.active = String(item === active); });
                moveBlob(active);
            });
        }, { rootMargin: '-45% 0px -50% 0px' });
        targets.forEach((target) => spy.observe(target));
    }

    let lastY = window.scrollY;
    let waiting = false;

    const update = () => {
        waiting = false;
        const y = window.scrollY;
        const max = document.documentElement.scrollHeight - window.innerHeight;

        if (progress) progress.style.transform = `scaleX(${max > 0 ? Math.min(1, y / max) : 0})`;
        if (bar) bar.dataset.scrolled = String(y > 24);
        logo?.style.setProperty('--mouth', Math.min(1, y / 320).toFixed(2));

        // La barre se replie quand on descend et revient des qu'on remonte (jamais quand le menu est ouvert).
        const menuOpen = document.documentElement.classList.contains('overflow-hidden');
        if (menuOpen) header.dataset.hidden = 'false';
        else if (y > 260 && y > lastY + 4) header.dataset.hidden = 'true';
        else if (y < lastY - 4 || y <= 260) header.dataset.hidden = 'false';
        lastY = y;
    };

    window.addEventListener('scroll', () => {
        if (!waiting) {
            waiting = true;
            requestAnimationFrame(update);
        }
    }, { passive: true });
    update();
}

/* -------------------------------------------------------------------------------------------------
   Chargeur entre les pages : si la page suivante tarde (plus de 140 ms), un voile bleu apparait avec la marque qui
   rebondit. Il disparait tout seul avec la nouvelle page, ou au retour arriere du navigateur.
   ------------------------------------------------------------------------------------------------- */
function initPageLoader() {
    if (reduceMotion()) return;

    let timer = 0;
    let loader = null;

    const show = () => {
        if (loader) return;
        loader = document.createElement('div');
        loader.className = 'page-loader';
        loader.setAttribute('aria-hidden', 'true');
        loader.innerHTML = `
            <span class="page-loader-shadow"></span>
            <svg viewBox="0 0 48 48" class="logo-mark page-loader-mark relative h-16 w-16" aria-hidden="true">
                <path class="lm-top" d="M5 21a17 17 0 0 1 34 0Z" fill="#fff"/>
                <path class="lm-bottom" d="M9 27a17 17 0 0 0 34 0Z" fill="#FFB400"/>
            </svg>`;
        document.body.appendChild(loader);
        requestAnimationFrame(() => loader.classList.add('is-on'));
    };

    // Le chargeur n'apparait que si la navigation a vraiment lieu : un formulaire ou un lien pris en charge par
    // JavaScript (preventDefault, apres notre ecoute en capture) ne change pas de page.
    const arm = (event) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (!event?.defaultPrevented) show();
        }, 140);
    };

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if ((link.target && link.target !== '_self') || link.hasAttribute('download')) return;

        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        if (url.pathname === window.location.pathname && url.search === window.location.search) return; // ancre ou meme page

        arm(event);
    }, true);

    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented || event.target.target === '_blank' || event.target.hasAttribute('data-no-loader')) return;
        arm(event);
    }, true);

    window.addEventListener('pageshow', () => {
        clearTimeout(timer);
        loader?.remove();
        loader = null;
    });
}

/** Une panne d'un effet ne doit jamais empêcher les autres de démarrer (ni l'ouverture du site). */
const safely = (fn) => {
    try {
        fn();
    } catch (error) {
        console.error(error);
    }
};

export function initMotion() {
    safely(() => document.querySelectorAll('[data-chat-demo]').forEach(initChatDemo));

    // Appareil pliable interactif : son moteur (dialogue local, alertes, pliage) se charge à part.
    document.querySelectorAll('[data-fold-play]').forEach((root) => {
        import('./playground/index.js')
            .then((module) => module.initPlayground(root, { animate: !reduceMotion() }))
            .catch(() => { root.dataset.ready = ''; });
    });
    safely(initParallax);
    safely(initCurrencySwitchers);
    safely(initCounters);
    safely(initSiteNav);
    safely(initPageLoader);
}
