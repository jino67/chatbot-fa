{{--
    Navigation du site public : une pastille flottante, comme un ilot. Au defilement elle se contracte au centre ;
    une « goutte » suit le survol et la section lue ; une fine ligne safran, dans le bord arrondi, montre l'avancement
    de la page ; la barre se replie a la descente et revient a la remontee ; le « o » du logo s'ouvre peu a peu.
    Ordinateur : liens en ligne. Tablette et mobile : bouton menu, dont le panneau s'ouvre en cercle depuis le bouton.
    Comportement : resources/js/motion.js (initSiteNav) et effects.js (aimant du bouton).
--}}
@props(['name', 'landing' => true])
@php
    // Hors de la page d'accueil, les ancres renvoient vers elle ; « Developpeurs » est une page a part.
    $anchor = fn ($hash) => $landing ? $hash : route('home').$hash;
    $links = [[$anchor('#fonctionnement'), 'Comment ça marche'], [$anchor('#metiers'), 'Métiers'], [$anchor('#whatsapp'), 'WhatsApp'], [$anchor('#tarifs'), 'Tarifs'], [route('developers'), 'Développeurs']];
@endphp
<header data-site-nav x-data="{ open: false }" @keydown.escape.window="open = false" @resize.window="if (window.innerWidth >= 1024) open = false"
        x-effect="document.documentElement.classList.toggle('overflow-hidden', open)"
        class="fixed inset-x-0 top-0 z-50 px-3 pt-3 transition-transform duration-500 ease-out data-[hidden=true]:-translate-y-[130%] sm:px-6">
    <div data-nav-bar data-scrolled="false"
         class="nav-bar relative mx-auto flex max-w-6xl items-center justify-between gap-2 overflow-visible border border-white/60 bg-white/75 py-1 pl-4 pr-1.5 shadow-lift backdrop-blur-md data-[scrolled=true]:border-slate-200 data-[scrolled=true]:bg-white/90 data-[scrolled=true]:shadow-pop sm:pl-5">
        <a href="{{ route('home') }}" aria-label="{{ $name }}, accueil" class="shrink-0"><x-logo /></a>

        <nav data-nav-links class="relative hidden items-center rounded-full lg:flex" aria-label="Sections de la page">
            <span data-nav-blob class="nav-blob pointer-events-none absolute left-0 top-0 h-full w-0 bg-brand-50 opacity-0" aria-hidden="true"></span>
            @foreach ($links as [$href, $label])
                <a data-nav-link data-active="false" href="{{ $href }}"
                   class="relative whitespace-nowrap px-3 py-2 text-sm font-medium text-slate-700 transition-colors duration-300 hover:text-brand-800 data-[active=true]:text-brand-800 xl:px-3.5">{{ $label }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-1">
            {{-- Le choix de la devise est aussi dans la section des tarifs : la barre le garde seulement sur grand écran. --}}
            <x-currency-menu class="nav-extra hidden 2xl:block" />

            @auth
                <a href="{{ route('dashboard') }}" class="btn-primary nav-cta whitespace-nowrap">Mon espace</a>
            @else
                <a href="{{ route('login') }}" class="nav-cta-ghost nav-extra hidden whitespace-nowrap px-3.5 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100 hover:text-brand-800 lg:inline-block">Se connecter</a>
                <a data-magnetic href="{{ route('register') }}" class="group btn-accent nav-cta whitespace-nowrap">
                    <span class="cta-long hidden min-[440px]:max-xl:inline xl:inline">Créer mon assistant</span><span class="cta-short min-[440px]:hidden">Essayer</span>
                    <svg viewBox="0 0 20 20" class="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h11M11 5l5 5-5 5"/></svg>
                </a>
            @endauth

            <button type="button" @click="open = !open" :aria-expanded="open" aria-controls="menu-mobile" aria-label="Menu"
                    :class="open ? 'rotate-90 rounded-[0.95rem_0.35rem_0.95rem_0.35rem] bg-brand-800' : 'rounded-full bg-brand-600'"
                    class="grid h-10 w-10 shrink-0 place-items-center text-white transition-[border-radius,transform,background-color] duration-500 ease-[cubic-bezier(0.3,1.4,0.5,1)] hover:bg-brand-700 lg:hidden">
                <svg viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 6h12" class="origin-center transition duration-300" :class="open && 'translate-y-[4px] rotate-45'"/>
                    <path d="M4 10h12" class="transition duration-200" :class="open && 'opacity-0'"/>
                    <path d="M4 14h12" class="origin-center transition duration-300" :class="open && '-translate-y-[4px] -rotate-45'"/>
                </svg>
            </button>
        </div>

        {{-- Avancement de la page : une ligne safran dans le bord arrondi de la pastille --}}
        <span class="pointer-events-none absolute inset-0 overflow-hidden" style="border-radius: inherit" aria-hidden="true">
            <span data-nav-progress class="absolute inset-x-0 bottom-0 h-[2.5px] origin-left scale-x-0 bg-gradient-to-r from-accent-400 to-accent-500"></span>
        </span>
    </div>

    {{-- Menu tablette et mobile : un cercle qui grandit depuis le bouton, puis les liens arrivent l'un apres l'autre --}}
    <div id="menu-mobile" class="menu-panel wax fixed inset-0 -z-10 flex flex-col justify-between overflow-y-auto px-6 pb-8 pt-28 text-white lg:hidden" :data-open="open" :aria-hidden="!open" @click.self="open = false">
        <ul class="space-y-1">
            @foreach ($links as $i => [$href, $label])
                <li class="menu-item" style="--i: {{ $i }}">
                    <a href="{{ $href }}" @click="open = false" class="flex items-center justify-between border-b border-white/10 py-3.5 font-display text-2xl font-bold transition hover:translate-x-1 hover:text-accent-300">
                        {{ $label }}
                        <svg viewBox="0 0 20 20" class="h-5 w-5 text-white/50" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h11M11 5l5 5-5 5"/></svg>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="menu-item mt-8 space-y-5" style="--i: {{ count($links) }}">
            <div>
                <p class="mb-2 text-xs text-white/60">Afficher les prix en</p>
                <x-currency-switcher tone="dark" />
            </div>
            @guest
                <div class="flex flex-col gap-3">
                    <a href="{{ route('register') }}" class="btn-accent justify-center py-3.5 text-base">Créer mon assistant gratuitement</a>
                    <a href="{{ route('login') }}" class="btn justify-center border border-white/40 py-3.5 text-base text-white hover:bg-white/10">Se connecter</a>
                </div>
            @endguest
        </div>
    </div>
</header>
