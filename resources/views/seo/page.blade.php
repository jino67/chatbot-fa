@php
    $name = $brand['name'];
    $full = mb_strlen($p['title'].' | '.$name) <= 70 ? $p['title'].' | '.$name : $p['title'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo :title="$full" :description="$p['description']" :canonical="$p['url']" :type="$p['type'] === 'guide' ? 'article' : 'website'" :modified="$p['updated']" :graph="$p['graph']" />
    <x-pwa-meta />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=unbounded:500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    <script>try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches&&document.referrer&&new URL(document.referrer).origin===location.origin){document.documentElement.classList.add('page-arriving')}}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white">

<x-site-nav :name="$name" :landing="false" />

<main>
    {{-- Haut de page : fil d'Ariane, un seul titre principal, l'essentiel en deux phrases --}}
    <section class="wax relative overflow-hidden text-white" data-ripple-field>
        <div class="relative mx-auto max-w-4xl px-4 pb-24 pt-28 sm:px-6 lg:pb-32 lg:pt-32">
            <nav aria-label="Fil d'Ariane" class="text-sm text-white/75">
                <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <li><a href="{{ route('home') }}" class="hover:text-white">Accueil</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('seo.hub') }}" class="hover:text-white">Ressources</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-white" aria-current="page">{{ $p['label'] }}</li>
                </ol>
            </nav>
            <h1 class="mt-6 max-w-3xl font-display text-[1.75rem] font-bold leading-[1.15] sm:text-[2.25rem] lg:text-[2.5rem]">{{ $p['h1'] }}</h1>
            <p class="mt-5 max-w-2xl text-lg leading-relaxed text-white/90">{{ $p['lead'] }}</p>
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="{{ route('register') }}" class="btn-accent px-7 py-3.5 text-base">Créer mon assistant gratuitement</a>
                <a href="{{ route('home') }}#tarifs" class="btn border border-white/40 px-7 py-3.5 text-base text-white hover:bg-white/10">Voir les tarifs</a>
            </div>
        </div>
        <x-wave fill="#ffffff" />
    </section>

    <article class="mx-auto max-w-3xl px-4 pb-6 pt-14 sm:px-6 lg:pt-20">
        @if ($p['facts'])
            <dl class="grid gap-x-8 gap-y-4 rounded-3xl rounded-bl-lg bg-brand-50 p-6 sm:grid-cols-2">
                @foreach ($p['facts'] as $label => $value)
                    <div>
                        <dt class="text-sm font-semibold text-brand-900">{{ $label }}</dt>
                        <dd class="mt-0.5 text-slate-700">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($p['dialog'])
            <figure class="mt-10" aria-label="Exemple de conversation">
                <figcaption class="mb-3 text-sm font-semibold text-slate-500">Exemple de conversation</figcaption>
                <div class="space-y-3">
                    @foreach ($p['dialog'] as [$who, $line])
                        @php $isClient = $loop->first; @endphp
                        <div class="flex {{ $isClient ? '' : 'justify-end' }}">
                            <div class="max-w-[85%]">
                                <p class="rounded-2xl px-4 py-2.5 text-[15px] leading-relaxed {{ $isClient ? 'rounded-bl-sm bg-slate-100 text-slate-800' : 'rounded-br-sm bg-brand-600 text-white' }}">{{ $line }}</p>
                                <p class="mt-1 text-[11px] text-slate-500 {{ $isClient ? '' : 'text-right' }}">{{ $who }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </figure>
        @endif

        @foreach ($p['sections'] as $section)
            <section class="mt-12">
                <h2 class="font-display text-xl font-bold leading-snug text-brand-950 sm:text-2xl">{{ $section['title'] }}</h2>
                @foreach ($section['text'] as $paragraph)
                    <p class="mt-4 text-[17px] leading-relaxed text-slate-700">{{ $paragraph }}</p>
                @endforeach
                @if ($section['list'])
                    <ul class="mt-4 space-y-2.5 text-[17px] leading-relaxed text-slate-700">
                        @foreach ($section['list'] as $item)
                            <li class="flex items-start gap-3"><span class="mt-1 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-feuille-500 text-white"><x-icon name="check" class="h-3 w-3" /></span><span>{{ $item }}</span></li>
                        @endforeach
                    </ul>
                @endif
                @if ($section['steps'])
                    <ol class="mt-4 space-y-4 text-[17px] leading-relaxed text-slate-700">
                        @foreach ($section['steps'] as $step)
                            <li class="flex items-start gap-4"><span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white">{{ $loop->iteration }}</span><span>{{ $step }}</span></li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endforeach

        @if ($p['faq'])
            <section class="mt-16" aria-labelledby="faq-title">
                <h2 id="faq-title" class="font-display text-xl font-bold text-brand-950 sm:text-2xl">Questions fréquentes</h2>
                <div class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                    @foreach ($p['faq'] as [$q, $a])
                        <details class="group py-5">
                            <summary class="flex cursor-pointer items-center justify-between gap-4 font-semibold text-brand-950 transition hover:text-brand-700">
                                {{ $q }}
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600 transition duration-300 group-open:rotate-45 group-open:bg-brand-600 group-open:text-white" aria-hidden="true"><x-icon name="x" class="h-4 w-4 rotate-45" /></span>
                            </summary>
                            <p class="pt-3 leading-relaxed text-slate-600">{{ $a }}</p>
                        </details>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($p['related'])
            <nav class="mt-16" aria-labelledby="related-title">
                <h2 id="related-title" class="font-display text-xl font-bold text-brand-950">À lire ensuite</h2>
                <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ($p['related'] as $link)
                        <li><a href="{{ $link['url'] }}" class="block rounded-2xl rounded-bl-md border border-slate-200 px-4 py-3.5 font-medium text-brand-800 transition hover:-translate-y-0.5 hover:border-brand-300 hover:bg-brand-50">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
                <p class="mt-4 text-sm"><a href="{{ route('seo.hub') }}" class="font-medium text-brand-700 underline">Toutes les ressources</a></p>
            </nav>
        @endif
    </article>

    {{-- Appel à l'action --}}
    <section class="wax relative mt-16 overflow-hidden text-white" data-ripple-field>
        <x-wave fill="#ffffff" position="top" size="sm" :layers="1" />
        <div class="relative mx-auto max-w-3xl px-4 pb-24 pt-24 text-center sm:px-6 lg:pb-28 lg:pt-28">
            <h2 class="font-display text-2xl font-bold leading-tight sm:text-3xl">{{ $p['cta'][0] }}</h2>
            <p class="mx-auto mt-4 max-w-xl text-lg text-white/90">{{ $p['cta'][1] ?? '' }}</p>
            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                <a href="{{ route('register') }}" class="btn-accent px-7 py-3.5 text-base">Créer mon assistant gratuitement</a>
                @if ($brand['whatsapp'])
                    <a href="{{ 'https://wa.me/'.$brand['whatsapp'].'?text='.rawurlencode("Bonjour, je voudrais en savoir plus sur {$name}.") }}" target="_blank" rel="noopener" class="btn border border-white/40 px-7 py-3.5 text-base text-white hover:bg-white/10">Écrire sur WhatsApp</a>
                @endif
            </div>
        </div>
        <x-wave fill="#0B1340" />
    </section>
</main>

<x-site-footer :name="$name" :aliases="[]" :wa="null" :landing="false" />
</body>
</html>
