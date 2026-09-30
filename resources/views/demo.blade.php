<!DOCTYPE html>
<html lang="{{ $bot->language }}" dir="{{ $bot->language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Démonstration : {{ $bot->name }} | {{ $brand['name'] }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-mist">

    {{-- Bandeau publicitaire : cette page est aussi notre vitrine. --}}
    <div class="wax-navy text-white">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <a href="{{ route('home') }}" aria-label="{{ $brand['name'] }}, accueil"><x-logo tone="light" size="sm" /></a>
            <p class="hidden text-sm text-white/85 md:block">Cet assistant a été créé en dix minutes, à partir de documents et de photos.</p>
            <a href="{{ route('register') }}" class="btn-accent">Créer le mien gratuitement</a>
        </div>
    </div>

    <main class="mx-auto max-w-5xl px-4 py-14 sm:px-6 sm:py-20">
        <div class="grid items-start gap-10 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div>
                <p class="inline-flex rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700 ring-1 ring-inset ring-brand-600/20">Page de démonstration</p>
                <h1 class="mt-4 font-display text-4xl font-bold leading-tight text-brand-950">{{ $bot->company() }}</h1>
                <p class="mt-4 max-w-xl text-lg leading-relaxed text-slate-700">
                    Voici comment l'assistant <strong>{{ $bot->name }}</strong> accueille vos clients. Cliquez sur la bulle en bas de la page et posez-lui une vraie question : prix, horaires, livraison, rendez-vous.
                </p>
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <button type="button" onclick="window.KoumaWidget && window.KoumaWidget.open()" class="btn-primary px-5 py-3 text-base">Discuter avec l'assistant</button>
                    <a href="{{ route('register') }}" class="btn-outline px-5 py-3 text-base">Créer mon assistant</a>
                </div>
                <p class="mt-5 max-w-xl text-sm text-slate-600">Cette page est une simulation : elle n'affiche pas le contenu réel du site de l'entreprise.</p>
            </div>

            <aside class="surface p-6">
                <h2 class="font-display text-lg font-bold">Pour votre entreprise</h2>
                <ul class="mt-4 space-y-3 text-sm text-slate-700">
                    <li class="flex gap-3"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /> Il lit vos documents, vos photos et le site de votre entreprise.</li>
                    <li class="flex gap-3"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /> Il répond sur votre site et sur WhatsApp, à toute heure.</li>
                    <li class="flex gap-3"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /> Il vous passe la main quand une question dépasse le robot.</li>
                    <li class="flex gap-3"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /> {{ $freePlan?->isFree() ? 'Gratuit pour démarrer, sans carte bancaire.' : 'Essai simple, sans engagement.' }}</li>
                </ul>
                <a href="{{ route('register') }}" class="btn-accent mt-6 w-full">Essayer avec mes documents</a>
            </aside>
        </div>
    </main>

    <footer class="border-t border-slate-200 py-6 text-center text-sm text-slate-600">
        Propulsé par <a href="{{ route('home') }}" class="font-semibold text-brand-700 hover:text-brand-900">{{ $brand['name'] }}</a>
    </footer>

    <script src="{{ url('/widget/widget.js') }}" data-bot="{{ $bot->public_key }}" data-open="true" async></script>
</body>
</html>
