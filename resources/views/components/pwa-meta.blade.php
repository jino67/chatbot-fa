{{-- Icônes et réglages pour « ajouter à l'écran d'accueil » (iPhone, iPad, Android, ordinateur) et couleur de la barre du navigateur. --}}
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icon-192.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ url('/manifest.webmanifest') }}">
<meta name="theme-color" content="#2340D9">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ \Illuminate\Support\Str::limit($brand['name'] ?? config('brand.name'), 12, '') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
