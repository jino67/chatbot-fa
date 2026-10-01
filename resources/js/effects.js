/**
 * Effets « eau » du site : defilement doux (Lenis), goutte d'ouverture, ondes qui suivent le pointeur,
 * cartes qui tombent comme des gouttes, inclinaison douce des cartes. Charge apres le rendu (import dynamique).
 * Tout est facultatif : sans ce fichier, ou avec « reduire les animations », la page reste complete.
 */
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
import 'lenis/dist/lenis.css';

gsap.registerPlugin(ScrollTrigger);

/* -------------------------------------------------------------------------------------------------
   Defilement doux. Lenis est pilote par le ticker de GSAP : une seule horloge pour le defilement,
   les animations liees au defilement et le dessin des ondes (pas de double boucle).
   ------------------------------------------------------------------------------------------------- */
function initLenis() {
    const lenis = new Lenis({
        lerp: 0.09,
        smoothWheel: true,
        wheelMultiplier: 0.95,
        touchMultiplier: 1.4,
        allowNestedScroll: true, // les zones defilantes (conversations, fenetres) gardent leur defilement natif
        autoRaf: false,
    });

    lenis.on('scroll', ScrollTrigger.update);
    gsap.ticker.add((time) => lenis.raf(time * 1000));
    gsap.ticker.lagSmoothing(0);

    // Les liens d'ancre glissent au lieu de sauter, en tenant compte de l'en-tete fixe.
    document.querySelectorAll('a[href^="#"]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const id = link.getAttribute('href');
            const target = id && id.length > 1 ? document.querySelector(id) : null;
            if (!target) return;

            event.preventDefault();
            lenis.scrollTo(target, { offset: -72, duration: 1.3 });
            history.replaceState(null, '', id);
        });
    });

    // Un bloc qui s'ouvre (FAQ) change la hauteur de la page : on recalcule apres sa transition.
    document.addEventListener('toggle', () => setTimeout(() => { lenis.resize(); ScrollTrigger.refresh(); }, 340), true);

    return lenis;
}

/* -------------------------------------------------------------------------------------------------
   Ondes : chaque « goutte » lance trois anneaux qui s'elargissent et s'effacent, dessines sur un canevas
   place sous le contenu. Elles suivent le pointeur (souris), reagissent au clic, et se declenchent quand
   l'assistant de la demonstration repond (evenement kouma:drop, voir motion.js).
   ------------------------------------------------------------------------------------------------- */
function createRippleField(host) {
    const canvas = document.createElement('canvas');
    canvas.className = 'pointer-events-none absolute inset-0 h-full w-full';
    canvas.setAttribute('aria-hidden', 'true');
    host.prepend(canvas);

    const ctx = canvas.getContext('2d');
    const rings = [];
    let width = 0;
    let height = 0;
    let visible = true;
    let dirty = false;

    const resize = () => {
        const box = host.getBoundingClientRect();
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        width = box.width;
        height = box.height;
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    };
    resize();
    new ResizeObserver(resize).observe(host);
    new IntersectionObserver((entries) => { visible = entries[0].isIntersecting; }).observe(host);

    gsap.ticker.add(() => {
        if (!visible || (!rings.length && !dirty)) return;

        ctx.clearRect(0, 0, width, height);
        rings.forEach((ring) => {
            if (ring.alpha <= 0.004 || ring.radius <= 0.5) return;
            ctx.beginPath();
            ctx.arc(ring.x, ring.y, ring.radius, 0, Math.PI * 2);
            ctx.globalAlpha = ring.alpha;
            ctx.lineWidth = ring.line;
            ctx.strokeStyle = ring.color;
            ctx.stroke();

            // Reflet fin juste a l'exterieur de l'onde : l'eau qui brille.
            ctx.beginPath();
            ctx.arc(ring.x, ring.y, ring.radius + 2.6, 0, Math.PI * 2);
            ctx.globalAlpha = ring.alpha * 0.4;
            ctx.lineWidth = Math.max(0.3, ring.line * 0.55);
            ctx.strokeStyle = '#FFFFFF';
            ctx.stroke();
        });
        ctx.globalAlpha = 1;
        dirty = rings.length > 0;
    });

    function drop(x, y, power = 1) {
        const reach = (150 + Math.random() * 70) * power;

        // Trois anneaux fins : le premier blanc, le deuxieme chaud (safran clair), le troisieme presque invisible.
        [[0, 0.38, 1.5, '#FFFFFF'], [0.24, 0.26, 1.2, '#FFE9A8'], [0.5, 0.16, 1, '#FFFFFF']].forEach(([delay, alpha, line, color], i) => {
            const ring = { x, y, radius: 0, alpha, line, color };
            const duration = 2.9 - i * 0.25;
            rings.push(ring);
            dirty = true;

            gsap.to(ring, { radius: reach * (1 - i * 0.14), duration, delay, ease: 'sine.out' });
            gsap.to(ring, {
                alpha: 0,
                line: 0.3,
                duration,
                delay,
                ease: 'power1.in',
                onComplete: () => {
                    const at = rings.indexOf(ring);
                    if (at > -1) rings.splice(at, 1);
                },
            });
        });
    }

    let last = { x: -999, y: -999, time: 0 };

    host.addEventListener('pointermove', (event) => {
        if (event.pointerType !== 'mouse') return;
        const box = host.getBoundingClientRect();
        const x = event.clientX - box.left;
        const y = event.clientY - box.top;
        const now = performance.now();

        if (now - last.time > 220 && Math.hypot(x - last.x, y - last.y) > 140) {
            drop(x, y, 0.5);
            last = { x, y, time: now };
        }
    });

    host.addEventListener('pointerdown', (event) => {
        const box = host.getBoundingClientRect();
        drop(event.clientX - box.left, event.clientY - box.top, 1.15);
    });

    window.addEventListener('kouma:drop', (event) => {
        if (!visible) return;
        const box = host.getBoundingClientRect();
        const { x, y } = event.detail;
        if (x >= box.left && x <= box.right && y >= box.top && y <= box.bottom) {
            drop(x - box.left, y - box.top, 0.8);
        }
    });
}

/* Onde sous un bouton au clic : deux anneaux partent du point touche. */
function initButtonRipples() {
    document.addEventListener('pointerdown', (event) => {
        const button = event.target.closest('.btn, .btn-primary, .btn-accent, .btn-outline, .btn-danger');
        if (!button || button.disabled) return;

        const box = button.getBoundingClientRect();
        const x = event.clientX - box.left;
        const y = event.clientY - box.top;
        const size = Math.max(box.width, box.height) * 2.4;

        const ring = document.createElement('span');
        ring.className = 'pointer-events-none absolute rounded-full border border-current';
        ring.style.cssText = `left:${x}px;top:${y}px;width:${size}px;height:${size}px;margin:${-size / 2}px 0 0 ${-size / 2}px;background:radial-gradient(circle, transparent 55%, currentColor 100%);opacity:0`;
        button.appendChild(ring);

        gsap.fromTo(ring, { scale: 0.05, opacity: 0.32 }, {
            scale: 1,
            opacity: 0,
            duration: 1,
            ease: 'sine.out',
            onComplete: () => ring.remove(),
        });
    }, { passive: true });
}

/* Inclinaison douce des cartes vers le pointeur. */
function initTilt() {
    if (!window.matchMedia('(hover: hover)').matches) return;

    document.querySelectorAll('[data-tilt]').forEach((card) => {
        gsap.set(card, { transformPerspective: 900 });
        const rotateX = gsap.quickTo(card, 'rotationX', { duration: 0.5, ease: 'power3.out' });
        const rotateY = gsap.quickTo(card, 'rotationY', { duration: 0.5, ease: 'power3.out' });

        card.addEventListener('pointermove', (event) => {
            const box = card.getBoundingClientRect();
            rotateY(((event.clientX - box.left) / box.width - 0.5) * 7);
            rotateX(-((event.clientY - box.top) / box.height - 0.5) * 7);
        });

        card.addEventListener('pointerleave', () => {
            rotateX(0);
            rotateY(0);
        });
    });
}

/* Appareil pliable : il s'incline doucement vers le pointeur, et l'eclat sur le titane poli suit la lumiere. */
function initFoldTilt() {
    if (!window.matchMedia('(hover: hover)').matches) return;

    document.querySelectorAll('[data-fold-tilt]').forEach((tilt) => {
        const root = tilt.closest('.fold');
        const device = tilt.querySelector('.fold-device');
        if (!root || !device) return;

        const rotateX = gsap.quickTo(tilt, 'rotationX', { duration: 0.7, ease: 'power3.out' });
        const rotateY = gsap.quickTo(tilt, 'rotationY', { duration: 0.7, ease: 'power3.out' });

        root.addEventListener('pointermove', (event) => {
            const box = root.getBoundingClientRect();
            const px = (event.clientX - box.left) / box.width - 0.5;
            const py = (event.clientY - box.top) / box.height - 0.5;

            rotateY(px * 9);
            rotateX(-py * 6);
            device.style.setProperty('--gx', `${Math.round((px + 0.5) * 100)}%`);
            device.style.setProperty('--gy', `${Math.round((py + 0.5) * 100)}%`);
        });

        root.addEventListener('pointerleave', () => {
            rotateX(0);
            rotateY(0);
            device.style.setProperty('--gx', '28%');
            device.style.setProperty('--gy', '12%');
        });
    });
}
/* Anneau plat sous une carte qui vient de « tomber » : l'onde d'une goutte sur une surface. */
function landingRing(group, card) {
    const ring = document.createElement('span');
    ring.className = 'pointer-events-none absolute rounded-full border-2 border-brand-500/50';
    ring.style.cssText = `left:${card.offsetLeft + card.offsetWidth / 2 - 40}px;top:${card.offsetTop + card.offsetHeight - 6}px;width:80px;height:80px`;
    group.appendChild(ring);

    gsap.fromTo(ring, { scaleX: 0.3, scaleY: 0.08, opacity: 0.7 }, {
        scaleX: 5.5,
        scaleY: 1.1,
        opacity: 0,
        duration: 1.1,
        ease: 'power2.out',
        onComplete: () => ring.remove(),
    });
}

/* -------------------------------------------------------------------------------------------------
   Effets lies au defilement.
   ------------------------------------------------------------------------------------------------- */
function initScrollEffects() {
    // Les titres « emergent » : nets, sans flou, en douceur.
    document.querySelectorAll('[data-emerge]').forEach((el) => {
        gsap.from(el, {
            y: 28,
            opacity: 0,
            filter: 'blur(8px)',
            duration: 1.1,
            ease: 'power3.out',
            scrollTrigger: { trigger: el, start: 'top 88%', once: true },
        });
    });

    // Cartes qui tombent comme des gouttes, chacune suivie d'une onde.
    document.querySelectorAll('[data-drop-in]').forEach((group) => {
        const cards = Array.from(group.children);
        gsap.set(cards, { y: -70, opacity: 0 });

        ScrollTrigger.create({
            trigger: group,
            start: 'top 82%',
            once: true,
            onEnter: () => {
                cards.forEach((card, i) => {
                    gsap.to(card, {
                        y: 0,
                        opacity: 1,
                        duration: 1.05,
                        delay: i * 0.12,
                        ease: 'bounce.out',
                        onComplete: () => landingRing(group, card),
                    });
                });
            },
        });
    });

    // Les formes decoratives montent plus vite que la page : effet de profondeur.
    document.querySelectorAll('[data-scroll-parallax]').forEach((el) => {
        gsap.to(el, {
            y: Number(el.dataset.scrollParallax) || -50,
            ease: 'none',
            scrollTrigger: { trigger: el.parentElement, start: 'top top', end: 'bottom top', scrub: true },
        });
    });

    // Le grand logo du pied de page : le « o » s'ouvre quand on arrive en bas, comme une bouche qui parle.
    document.querySelectorAll('[data-footer-mark]').forEach((mark) => {
        const top = mark.querySelector('.lm-top');
        const bottom = mark.querySelector('.lm-bottom');
        if (!top || !bottom) return;

        gsap.timeline({ scrollTrigger: { trigger: mark, start: 'top 92%', once: true } })
            .to(top, { x: -5, y: -9, duration: 0.5, ease: 'back.out(2)' })
            .to(bottom, { x: 5, y: 9, duration: 0.5, ease: 'back.out(2)' }, '<')
            .to([top, bottom], { x: 0, y: 0, duration: 0.6, ease: 'elastic.out(1, 0.5)' }, '+=0.15');
    });

    // La ligne des etapes se remplit avec le defilement.
    document.querySelectorAll('.steps-line').forEach((line) => {
        gsap.fromTo(line, { '--fill': '0%' }, {
            '--fill': '100%',
            ease: 'none',
            scrollTrigger: { trigger: line, start: 'top 82%', end: 'top 38%', scrub: 0.6 },
        });
    });
}

/* -------------------------------------------------------------------------------------------------
   Ouverture : le « o » de la marque tombe vite, rebondit en s'ecrasant, puis se fend au milieu et les deux portes
   bleues s'ouvrent vers la gauche et la droite (page d'abord floue puis nette). Une fois tous les quinze minutes.
   L'ecran bleu est pose des le <head> (classe intro-pending) pour qu'aucun clignotement ne precede la chute.
   ------------------------------------------------------------------------------------------------- */
function playIntro(lenis) {
    const root = document.documentElement;
    if (!root.classList.contains('intro-pending')) return;

    try { localStorage.setItem('kouma-intro', String(Date.now())); } catch (e) { /* stockage bloque : l'ouverture se rejouera */ }

    const size = Math.round(Math.min(104, Math.max(72, window.innerWidth * 0.22)));
    const half = (side) => `
        <svg viewBox="0 0 48 48" width="${size}" height="${size}" class="intro-half absolute inset-0" style="clip-path:inset(0 ${side === 'l' ? '50% 0 0' : '0 0 50%'})" data-half="${side}">
            <path d="M5 21a17 17 0 0 1 34 0Z" fill="#fff"/>
            <path d="M9 27a17 17 0 0 0 34 0Z" fill="#FFB400"/>
        </svg>`;

    // Deux portes bleues qui se rejoignent au milieu ; la marque, coupee en deux, est posee sur la jonction.
    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 z-[100] overflow-hidden';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = `
        <div data-door="l" class="wax absolute inset-y-0 left-0 w-1/2" style="box-shadow:14px 0 46px rgba(11,19,64,.38)"></div>
        <div data-door="r" class="wax absolute inset-y-0 right-0 w-1/2" style="background-position:-50vw 0;box-shadow:-14px 0 46px rgba(11,19,64,.38)"></div>
        <span data-shadow class="absolute left-1/2 top-1/2 rounded-full bg-marine-950/40 blur-[3px]" style="width:${size * 0.9}px;height:${size * 0.16}px;margin-left:${-size * 0.45}px;margin-top:${size * 0.5 + 6}px"></span>
        <div data-logo class="absolute left-1/2 top-1/2" style="width:${size}px;height:${size}px;margin:${-size / 2}px 0 0 ${-size / 2}px">${half('l')}${half('r')}</div>`;
    document.body.appendChild(overlay);
    root.classList.remove('intro-pending');
    lenis?.stop();

    const logo = overlay.querySelector('[data-logo]');
    const shadow = overlay.querySelector('[data-shadow]');
    const doorL = overlay.querySelector('[data-door="l"]');
    const doorR = overlay.querySelector('[data-door="r"]');
    const halfL = overlay.querySelector('[data-half="l"]');
    const halfR = overlay.querySelector('[data-half="r"]');
    const drop = window.innerHeight / 2 + size;
    const travel = window.innerWidth * 0.51; // une porte + son ombre : elle sort entierement de l'ecran

    const timeline = gsap.timeline({
        onComplete: () => {
            overlay.remove();
            lenis?.start();
        },
    });

    // Chute rapide, puis trois rebonds de plus en plus courts ; a chaque impact la marque s'ecrase et son ombre s'elargit.
    timeline
        .set(logo, { y: -drop, rotation: -10, transformOrigin: '50% 100%' })
        .set(shadow, { scaleX: 0.15, opacity: 0 })
        .to(logo, { y: 0, rotation: 0, duration: 0.4, ease: 'power3.in' })
        .to(shadow, { scaleX: 1, opacity: 1, duration: 0.4, ease: 'power3.in' }, '<')
        .to(logo, { scaleX: 1.26, scaleY: 0.6, duration: 0.07, ease: 'power1.out' })
        .to(logo, { y: -size * 0.95, scaleX: 0.94, scaleY: 1.1, duration: 0.25, ease: 'power2.out' })
        .to(shadow, { scaleX: 0.62, opacity: 0.55, duration: 0.25, ease: 'power2.out' }, '<')
        .to(logo, { y: 0, scaleX: 1, scaleY: 1, duration: 0.22, ease: 'power2.in' })
        .to(shadow, { scaleX: 1, opacity: 1, duration: 0.22, ease: 'power2.in' }, '<')
        .to(logo, { scaleX: 1.16, scaleY: 0.76, duration: 0.05, ease: 'power1.out' })
        .to(logo, { y: -size * 0.36, scaleX: 0.97, scaleY: 1.05, duration: 0.16, ease: 'power2.out' })
        .to(logo, { y: 0, scaleX: 1, scaleY: 1, duration: 0.15, ease: 'power2.in' })
        .to(logo, { scaleX: 1.07, scaleY: 0.9, duration: 0.04, ease: 'power1.out' })
        .to(logo, { scaleX: 1, scaleY: 1, duration: 0.18, ease: 'back.out(3)' })
        .addLabel('open', '+=0.08')
        // Le « o » se fend au milieu : chaque moitie part avec sa porte, vers la gauche et vers la droite.
        .to([doorL, halfL], { x: -travel, duration: 1, ease: 'power3.inOut' }, 'open')
        .to([doorR, halfR], { x: travel, duration: 1, ease: 'power3.inOut' }, 'open')
        .to(shadow, { opacity: 0, scaleX: 1.5, duration: 0.35, ease: 'power1.out' }, 'open');

    // La page se precise pendant que les portes s'ecartent.
    const main = document.querySelector('main');
    if (main) {
        timeline.fromTo(main, { filter: 'blur(8px)', scale: 1.03, transformOrigin: '50% 38%' }, {
            filter: 'blur(0px)',
            scale: 1,
            duration: 1.5,
            ease: 'power3.out',
            clearProps: 'filter,transform',
        }, 'open+=0.05');
    }
}

/* Aimant : un bouton [data-magnetic] est attire, tres doucement, par le pointeur qui s'en approche. */
function initMagnetic() {
    if (!window.matchMedia('(hover: hover)').matches) return;

    document.querySelectorAll('[data-magnetic]').forEach((el) => {
        const moveX = gsap.quickTo(el, 'x', { duration: 0.5, ease: 'power3.out' });
        const moveY = gsap.quickTo(el, 'y', { duration: 0.5, ease: 'power3.out' });

        el.addEventListener('pointermove', (event) => {
            const box = el.getBoundingClientRect();
            moveX((event.clientX - (box.left + box.width / 2)) * 0.2);
            moveY((event.clientY - (box.top + box.height / 2)) * 0.28);
        });

        el.addEventListener('pointerleave', () => {
            moveX(0);
            moveY(0);
        });
    });
}
export function initEffects() {
    // Un effet en panne ne doit jamais bloquer les autres, ni laisser l'écran bleu d'ouverture sur la page.
    const safely = (fn) => {
        try {
            fn();
        } catch (error) {
            console.error(error);
        }
    };

    let lenis = null;
    safely(() => { lenis = initLenis(); });

    if (document.body.hasAttribute('data-intro')) {
        safely(() => playIntro(lenis));
    }
    document.documentElement.classList.remove('intro-pending');

    safely(initButtonRipples);
    safely(() => document.querySelectorAll('[data-ripple-field]').forEach(createRippleField));
    safely(initTilt);
    safely(initMagnetic);
    safely(initFoldTilt);
    safely(initScrollEffects);
}
