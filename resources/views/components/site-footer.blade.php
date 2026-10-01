{{--
    Pied de page : un bandeau de salutations dans les langues que l'assistant parle, puis les liens, puis un tres grand
    logo dont le « o » s'ouvre quand on arrive en bas (data-footer-mark, voir effects.js).
--}}
@props(['name', 'aliases' => [], 'wa' => null, 'landing' => true])
@php
    // Salutations verifiees dans des sources publiques ; a faire relire par un locuteur avant une campagne.
    $greetings = [
        ['Bonjour', 'français'], ['Hello', 'anglais'], ['مرحبا', 'arabe'],
        ['I ni ce', 'bambara, dioula'], ['Ne y windiga', 'mooré'], ['Jam waali', 'peul'],
    ];
    $pageLinks = fn (array $keys) => collect($keys)->map(fn ($k) => \App\Support\SeoPages::find($k))->filter()->map(fn ($p) => [\App\Support\SeoPages::url($p['key']), $p['label']])->values()->all();
    $aliasList = $aliases ? implode(', ', array_slice($aliases, 0, -1)).' ou '.end($aliases) : '';
@endphp
<footer class="relative overflow-hidden bg-brand-950 text-white">

    {{-- Salutations : l'assistant parle la langue de votre client --}}
    <div class="border-b border-white/10 py-7" aria-label="Bonjour dans plusieurs langues">
        <div class="overflow-hidden" data-marquee-mask>
            <div class="marquee" aria-hidden="true">
                @foreach ([1, 2] as $copy)
                    @foreach ($greetings as [$word, $lang])
                        <span class="mx-8 flex shrink-0 items-baseline gap-3 whitespace-nowrap">
                            <span class="font-display text-2xl font-bold sm:text-3xl" @if ($lang === 'arabe') dir="rtl" @endif>{{ $word }}</span>
                            <span class="text-sm text-white/50">{{ $lang }}</span>
                            <x-mark tone="light" class="ml-8 h-5 w-5 opacity-60" />
                        </span>
                    @endforeach
                @endforeach
            </div>
            <p class="sr-only">Bonjour, Hello, مرحبا, I ni ce, Ne y windiga, Jam waali : bonjour en français, anglais, arabe, bambara et dioula, mooré et peul.</p>
        </div>
    </div>

    <div class="relative mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2.5fr)]">
        <div class="max-w-sm">
            <x-logo tone="light" size="lg" />
            <p class="mt-4 text-white/75">{{ $brand['tagline'] }}</p>
            <p class="mt-3 text-sm text-white/50">{{ $name }} vient de « kuma », la parole en bambara et en dioula.@if ($aliases) On l'écrit aussi {{ $aliasList }}.@endif</p>

            @if ($wa)
                <a href="{{ $wa }}" target="_blank" rel="noopener" class="group mt-6 inline-flex items-center gap-3 rounded-2xl rounded-bl-md bg-white px-4 py-3 text-brand-950 shadow-pop transition hover:-translate-y-0.5">
                    <span class="grid h-9 w-9 place-items-center rounded-full bg-feuille-500 text-white"><x-icon name="chat" class="h-4 w-4" /></span>
                    <span class="leading-tight"><strong class="block text-sm font-semibold">Une question ?</strong><span class="text-xs text-slate-500">Écrivez-nous sur WhatsApp</span></span>
                </a>
            @endif

            <x-currency-switcher tone="dark" class="mt-6" />
        </div>

        <nav class="grid grid-cols-2 gap-x-8 gap-y-8 text-sm sm:grid-cols-3 lg:grid-cols-4" aria-label="Liens du pied de page">
            @foreach ([
                'Le produit' => [[$landing ? '#fonctionnement' : route('home').'#fonctionnement', 'Comment ça marche'], [$landing ? '#metiers' : route('home').'#metiers', 'Métiers'], [$landing ? '#whatsapp' : route('home').'#whatsapp', 'WhatsApp'], [$landing ? '#tarifs' : route('home').'#tarifs', 'Tarifs'], [route('developers'), 'API pour développeurs']],
                'Solutions et guides' => array_merge($pageLinks(['chatbot-whatsapp', 'assistant-virtuel-site-web', 'reponse-automatique-whatsapp-business', 'combien-coute-un-chatbot-whatsapp', 'creer-un-chatbot-whatsapp-pour-son-entreprise']), [[route('seo.hub'), 'Toutes les ressources']]),
                'Par métier et par pays' => $pageLinks(['chatbot-whatsapp-boutique', 'chatbot-whatsapp-restaurant', 'chatbot-whatsapp-clinique', 'chatbot-whatsapp-ecole', 'chatbot-whatsapp-immobilier', 'chatbot-whatsapp-burkina-faso', 'chatbot-whatsapp-cote-d-ivoire', 'chatbot-whatsapp-senegal']),
                'Informations' => array_values(array_filter([
                    [route('help.index'), 'Aide et guides'],
                    [route('register'), 'Créer un compte'],
                    [route('login'), 'Se connecter'],
                    [$landing ? '#questions' : route('home').'#questions', 'Questions fréquentes'],
                    [route('legal.terms'), 'Conditions d\'utilisation'],
                    [route('legal.privacy'), 'Confidentialité'],
                    $brand['email'] ? ['mailto:'.$brand['email'], $brand['email']] : null,
                ])),
            ] as $title => $links)
                <div>
                    <p class="font-display text-sm font-bold text-white">{{ $title }}</p>
                    <ul class="mt-4 space-y-2.5">
                        @foreach ($links as [$href, $label])
                            <li><a href="{{ $href }}" class="relative inline-block text-white/70 transition hover:translate-x-1 hover:text-accent-300">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
    </div>

    {{-- Tres grand logo : le « o » s'ouvre quand le pied de page arrive a l'ecran --}}
    <div class="pointer-events-none relative -mb-[0.16em] select-none overflow-hidden text-center leading-none" aria-hidden="true">
        <span data-footer-mark class="mouth inline-flex items-center font-display font-bold text-white/[0.07]" style="font-size: clamp(5.5rem, 27vw, 21rem)">k<x-mark class="mx-[0.03em] h-[0.9em] w-[0.9em] translate-y-[0.03em]" tone="light" />uma</span>
    </div>

    <p class="relative border-t border-white/10 bg-brand-950 py-4 text-center text-xs text-white/50">© {{ now()->year }} {{ $name }}</p>
</footer>
