<!DOCTYPE html>
<html lang="{{ $bot->language }}" dir="{{ $bot->language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ $color }}">
    <title>{{ $company }} : discutez avec notre assistant</title>
    <meta name="description" content="{{ $welcome }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Discutez avec {{ $company }}">
    <meta property="og:description" content="{{ $welcome }}">
    <meta property="og:url" content="{{ $bot->chatUrl() }}">
    <meta property="og:image" content="{{ url('og-image.png') }}">
    <link rel="icon" href="{{ url('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=unbounded:600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    {{-- Seulement le style : la page doit s'ouvrir vite sur un téléphone avec une connexion lente. --}}
    @vite(['resources/css/app.css'])
    <style>
        :root { --c: {{ $color }}; }
        .chat-bg { background: radial-gradient(120% 70% at 50% 0%, color-mix(in srgb, var(--c) 16%, #fff) 0%, #fff 62%); }
        .chat-avatar { background: var(--c); }
        .chat-btn { background: var(--c); }
        .chat-bubble { border-top-left-radius: .35rem; }
    </style>
</head>
<body class="chat-bg min-h-screen font-sans text-slate-800 antialiased">
    <main class="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center px-6 py-14 text-center">
        <span class="chat-avatar grid h-20 w-20 place-items-center rounded-full font-display text-3xl font-bold text-white shadow-lg" aria-hidden="true">{{ mb_strtoupper(mb_substr($company, 0, 1)) }}</span>

        <h1 class="mt-6 font-display text-2xl font-bold leading-tight text-slate-900 sm:text-3xl">{{ $company }}</h1>

        @if ($available)
            <p class="mt-2 text-sm text-slate-600">{{ $bot->name }} répond tout de suite, jour et nuit.</p>

            <p class="chat-bubble mt-8 rounded-2xl bg-white px-5 py-4 text-start text-base leading-relaxed shadow-sm ring-1 ring-slate-200">{{ $welcome }}</p>

            <div class="mt-8 flex w-full flex-col gap-3">
                <button type="button" onclick="window.KoumaWidget && window.KoumaWidget.open()" class="chat-btn rounded-full px-6 py-3.5 text-base font-semibold text-white shadow-md transition hover:brightness-110 focus:outline-none focus-visible:ring-4 focus-visible:ring-slate-300">Discuter maintenant</button>
                @if ($whatsapp)
                    <a href="https://wa.me/{{ $whatsapp }}?text={{ rawurlencode('Bonjour, je vous écris depuis votre lien de discussion.') }}" target="_blank" rel="noopener"
                       class="inline-flex items-center justify-center gap-2 rounded-full bg-white px-6 py-3.5 text-base font-semibold text-slate-800 ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50">
                        <svg viewBox="0 0 24 24" class="h-5 w-5 text-[#25D366]" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.39-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35M12.05 21.78h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.88 9.88M20.46 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.9 0-3.18-1.24-6.17-3.48-8.41"/></svg>
                        Continuer sur WhatsApp
                    </a>
                @endif
            </div>

            <p class="mt-6 max-w-xs text-xs leading-relaxed text-slate-500">Un assistant automatique vous répond à partir des informations de {{ $company }}. Vous pouvez demander à parler à une personne.</p>
        @else
            <p class="mt-6 rounded-2xl bg-white px-5 py-4 text-base leading-relaxed text-slate-700 shadow-sm ring-1 ring-slate-200">Notre assistant n'est pas disponible pour le moment. Merci de réessayer un peu plus tard, ou de nous contacter directement.</p>
        @endif
    </main>

    @if ($branding)
        <footer class="pb-6 text-center text-xs text-slate-500">
            Propulsé par <a href="{{ rtrim($brand['url'], '/') }}/?utm_source=chat_link&utm_medium=share&utm_campaign=powered_by&utm_content={{ $bot->public_key }}" class="font-semibold text-slate-700 underline-offset-2 hover:underline">{{ $brand['name'] }}</a>
        </footer>
    @endif

    @if ($available)
        <script src="{{ url('/widget/widget.js') }}" data-bot="{{ $bot->public_key }}" data-open="true" async></script>
    @endif
</body>
</html>
