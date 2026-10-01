import './bootstrap';

import Alpine from 'alpinejs';
import { initMotion } from './motion';
import { initAnalytics } from './analytics';
import { initPush } from './push';

window.Alpine = Alpine;

Alpine.start();

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function start() {
    try {
        initMotion();
    } catch (error) {
        console.error(error);
    }

    try {
        initAnalytics();
    } catch (error) {
        /* la mesure ne doit jamais gêner la page */
    }

    try {
        initPush();
    } catch (error) {
        /* les notifications ne doivent jamais gêner la page */
    }

    // Les effets « eau » (GSAP, Lenis) se chargent apres le rendu : la page reste utilisable sans eux.
    if (reduceMotion) {
        document.documentElement.classList.remove('intro-pending');
    } else {
        import('./effects')
            .then((module) => module.initEffects())
            .catch(() => document.documentElement.classList.remove('intro-pending'));
    }
}

// Service worker : rend l'application installable (écran d'accueil) et affiche une page claire hors connexion.
// Seulement en HTTPS (ou en local) : c'est une exigence des navigateurs.
if ('serviceWorker' in navigator && (window.location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(window.location.hostname))) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => { /* installation impossible : le site reste utilisable */ }));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
