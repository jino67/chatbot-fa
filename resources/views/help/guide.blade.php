@php
    $name = $brand['name'];
    $heading = $guide['title'];
    $title = $key === 'developpeur' ? 'Guide du développeur : widget et API de Kouma' : "Guide d'utilisation de Kouma pour les entreprises";
    $full = mb_strlen($title.' | '.$name) <= 70 ? $title.' | '.$name : $title;
    $url = route($meta['route']);
    $graph = [[
        '@type' => 'TechArticle',
        'headline' => $title,
        'description' => $meta['description'],
        'url' => $url,
        'inLanguage' => 'fr',
        'dateModified' => $guide['updated'],
        'author' => ['@type' => 'Organization', 'name' => $name, 'url' => url('/')],
        'publisher' => ['@type' => 'Organization', 'name' => $name, 'url' => url('/')],
        'mainEntityOfPage' => $url,
    ], [
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => route('home')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Aide et guides', 'item' => route('help.index')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $heading, 'item' => $url],
        ],
    ]];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo :title="$full" :description="$meta['description']" :canonical="$url" type="article" :modified="$guide['updated']" :graph="$graph" />
    <x-pwa-meta />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=unbounded:500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white">

<x-site-nav :name="$name" :landing="false" />

<main>
    <section class="wax relative overflow-hidden text-white" data-ripple-field>
        <div class="relative mx-auto max-w-5xl px-4 pb-20 pt-28 sm:px-6 lg:pb-24 lg:pt-32">
            <nav aria-label="Fil d'Ariane" class="text-sm text-white/75">
                <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <li><a href="{{ route('home') }}" class="hover:text-white">Accueil</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('help.index') }}" class="hover:text-white">Aide et guides</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-white" aria-current="page">{{ $heading }}</li>
                </ol>
            </nav>
            <h1 class="mt-6 max-w-3xl font-display text-[1.75rem] font-bold leading-[1.15] sm:text-[2.25rem] lg:text-[2.5rem]">{{ $heading }}</h1>
            <p class="mt-4 max-w-2xl text-lg leading-relaxed text-white/90">{{ $meta['subtitle'] }}. {{ $meta['description'] }}</p>
            <div class="mt-7 flex flex-wrap items-center gap-3">
                @if ($pdfUrl)
                    <a href="{{ $pdfUrl }}" download class="btn-accent px-6 py-3">Télécharger en PDF</a>
                @endif
                @if ($landingBotKey)
                    <button type="button" onclick="window.KoumaWidget && window.KoumaWidget.open()" class="btn border border-white/40 px-6 py-3 text-white hover:bg-white/10">Poser une question au chat</button>
                @endif
                <span class="text-sm text-white/70">Mis à jour le {{ \Carbon\Carbon::parse($guide['updated'])->translatedFormat('j F Y') }} · environ {{ max(1, (int) round($guide['words'] / 220)) }} min de lecture</span>
            </div>
        </div>
        <x-wave fill="#ffffff" />
    </section>

    <div class="mx-auto max-w-6xl px-4 pb-10 pt-12 sm:px-6 lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-12 lg:pt-16"
         x-data="{ active: '' }"
         x-init="if ('IntersectionObserver' in window) { const io = new IntersectionObserver((entries) => entries.forEach((e) => { if (e.isIntersecting) active = e.target.id; }), { rootMargin: '-15% 0px -70% 0px' }); $root.querySelectorAll('.guide h2[id], .guide h3[id]').forEach((h) => io.observe(h)); }">

        {{-- Sommaire : sur grand écran, collé à gauche ; sur mobile, replié au-dessus du texte --}}
        <aside class="mb-10 lg:mb-0">
            <details class="rounded-2xl bg-brand-50 p-4 lg:hidden">
                <summary class="cursor-pointer font-semibold text-brand-950">Sommaire du guide</summary>
                <nav class="guide-toc mt-3" aria-label="Sommaire">
                    @foreach ($guide['toc'] as $item)
                        <a href="#{{ $item['id'] }}" data-level="{{ $item['level'] }}" @click="$el.closest('details').open = false">{{ $item['title'] }}</a>
                    @endforeach
                </nav>
            </details>
            <nav class="guide-toc sticky top-24 hidden max-h-[calc(100vh-7rem)] overflow-y-auto lg:block" aria-label="Sommaire">
                <p class="mb-2 px-3 font-display text-xs font-bold uppercase tracking-wide text-slate-500">Sommaire</p>
                @foreach ($guide['toc'] as $item)
                    <a href="#{{ $item['id'] }}" data-level="{{ $item['level'] }}" :data-on="active === '{{ $item['id'] }}'">{{ $item['title'] }}</a>
                @endforeach
            </nav>
        </aside>

        <article class="min-w-0">
            <div class="guide max-w-3xl">{!! $guide['html'] !!}</div>

            <div class="mt-16 max-w-3xl">
                @include('help._contact')
            </div>

            @if ($pdfUrl)
                <div class="mt-8 flex max-w-3xl flex-wrap items-center justify-between gap-4 rounded-2xl bg-brand-50 p-5">
                    <p class="text-sm text-slate-700"><strong class="text-brand-950">Ce guide en PDF</strong>, à garder sous la main ou à partager avec votre équipe.</p>
                    <a href="{{ $pdfUrl }}" download class="btn-primary">Télécharger le PDF</a>
                </div>
            @endif

            <nav class="mt-8 max-w-3xl" aria-label="Autres guides">
                @foreach ($others as $other)
                    <a href="{{ $other['url'] }}" class="block rounded-2xl rounded-bl-md border border-slate-200 px-4 py-3.5 font-medium text-brand-800 transition hover:-translate-y-0.5 hover:border-brand-300 hover:bg-brand-50">À lire aussi : {{ $other['title'] }}</a>
                @endforeach
                <p class="mt-4 text-sm"><a href="{{ route('help.index') }}" class="font-medium text-brand-700 underline">Toute l'aide</a></p>
            </nav>

            @include('help._signature', ['class' => 'mt-16 max-w-3xl pb-6'])
        </article>
    </div>
</main>

<x-site-footer :name="$name" :aliases="[]" :wa="null" :landing="false" />

@if ($landingBotKey)
    <script src="{{ url('/widget/widget.js') }}" data-bot="{{ $landingBotKey }}" async></script>
@endif
</body>
</html>
