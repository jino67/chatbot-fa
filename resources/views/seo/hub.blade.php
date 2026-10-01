@php
    $name = $brand['name'];
    $full = mb_strlen($hub['title'].' | '.$name) <= 70 ? $hub['title'].' | '.$name : $hub['title'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo :title="$full" :description="$hub['description']" :canonical="route('seo.hub')" :graph="$hub['graph']" />
    <x-pwa-meta />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=unbounded:500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    <script>try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches&&document.referrer&&new URL(document.referrer).origin===location.origin){document.documentElement.classList.add('page-arriving')}}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white">

<x-site-nav :name="$name" :landing="false" />

<main>
    <section class="wax relative overflow-hidden text-white" data-ripple-field>
        <div class="relative mx-auto max-w-4xl px-4 pb-24 pt-28 sm:px-6 lg:pb-32 lg:pt-32">
            <nav aria-label="Fil d'Ariane" class="text-sm text-white/75">
                <ol class="flex flex-wrap items-center gap-x-2">
                    <li><a href="{{ route('home') }}" class="hover:text-white">Accueil</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-white" aria-current="page">Ressources</li>
                </ol>
            </nav>
            <h1 class="mt-6 max-w-3xl font-display text-[1.75rem] font-bold leading-[1.15] sm:text-[2.25rem] lg:text-[2.5rem]">Ressources pour répondre à vos clients sur WhatsApp</h1>
            <p class="mt-5 max-w-2xl text-lg leading-relaxed text-white/90">Des guides concrets, les solutions de {{ $name }} et des exemples par métier et par pays : tout ce qu'il faut savoir avant d'installer un assistant sur votre site et sur WhatsApp.</p>
        </div>
        <x-wave fill="#ffffff" />
    </section>

    <div class="mx-auto max-w-5xl space-y-14 px-4 pb-20 pt-14 sm:px-6 lg:pt-20">
        @foreach ($hub['groups'] as $type => $group)
            <section aria-labelledby="group-{{ $type }}">
                <h2 id="group-{{ $type }}" class="font-display text-xl font-bold text-brand-950 sm:text-2xl">{{ $group['title'] }}</h2>
                <ul class="mt-6 grid gap-4 sm:grid-cols-2">
                    @foreach ($group['pages'] as $page)
                        <li>
                            <a href="{{ $page['url'] }}" class="group block h-full rounded-3xl rounded-bl-lg border border-slate-200 p-5 transition hover:-translate-y-0.5 hover:border-brand-300 hover:bg-brand-50">
                                <span class="font-display font-bold text-brand-900 group-hover:text-brand-700">{{ $page['label'] }}</span>
                                <span class="mt-2 block text-sm leading-relaxed text-slate-600">{{ $page['description'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</main>

<x-site-footer :name="$name" :aliases="[]" :wa="null" :landing="false" />
</body>
</html>
