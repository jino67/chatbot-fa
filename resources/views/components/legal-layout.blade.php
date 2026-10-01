@props(['title', 'description' => null])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo :title="$title.' | '.$brand['name']" :description="$description ?: $title.' du service '.$brand['name'].'.'" />
    <x-pwa-meta />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=unbounded:500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    <script>try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches&&document.referrer&&new URL(document.referrer).origin===location.origin){document.documentElement.classList.add('page-arriving')}}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white">
    <header class="border-b border-slate-200">
        <div class="mx-auto flex h-16 max-w-3xl items-center justify-between px-4 sm:px-6">
            <a href="{{ route('home') }}" aria-label="{{ $brand['name'] }}, accueil"><x-logo /></a>
            <a href="{{ route('register') }}" class="btn-accent">Créer mon assistant</a>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <h1 class="font-display text-4xl font-bold text-brand-950">{{ $title }}</h1>
        <div class="mt-8 space-y-4 text-[15px] leading-relaxed text-slate-700 [&_h2]:mt-10 [&_h2]:font-display [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-brand-950 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:ps-6 [&_a]:font-medium [&_a]:text-brand-600 [&_a]:underline">
            {{ $slot }}
        </div>
    </main>

    <footer class="border-t border-slate-200 py-8 text-center text-sm text-slate-600">
        <a href="{{ route('legal.terms') }}" class="hover:text-brand-700">Conditions d'utilisation</a>
        <span class="mx-2">|</span>
        <a href="{{ route('legal.privacy') }}" class="hover:text-brand-700">Confidentialité</a>
        <span class="mx-2">|</span>
        <a href="{{ route('home') }}" class="hover:text-brand-700">{{ $brand['name'] }}</a>
    </footer>
</body>
</html>
