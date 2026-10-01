<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex">

        <title>{{ $title ?? $brand['name'] }}</title>
        <x-pwa-meta />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=unbounded:500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />

        <script>try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches&&document.referrer&&new URL(document.referrer).origin===location.origin){document.documentElement.classList.add('page-arriving')}}catch(e){}</script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        @php $user = auth()->user(); @endphp
        <div class="min-h-screen lg:flex" x-data="{ menu: false }">
            @include('layouts.sidebar')

            <div class="min-w-0 flex-1">
                {{-- Barre mobile : logo et menu --}}
                <div class="flex h-14 items-center justify-between border-b border-slate-200/80 bg-white px-4 lg:hidden">
                    <a href="{{ route('dashboard') }}"><x-logo size="sm" /></a>
                    <button type="button" @click="menu = true" class="rounded-lg p-2 text-brand-900 hover:bg-slate-100" aria-label="Ouvrir le menu">
                        <x-icon name="menu" />
                    </button>
                </div>

                @if ($user?->isActingAsClient())
                    <div class="bg-brand-950 px-4 py-2.5 text-sm text-white sm:px-6 lg:px-8">
                        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2">
                            <span>Vous gérez l'espace <strong class="font-semibold">{{ $user->currentWorkspace()->name }}</strong> en tant que {{ strtolower($user->roleLabel()) }}. Tout ce que vous modifiez ici est enregistré au nom de ce client.</span>
                            <form method="POST" action="{{ route('admin.workspaces.leave') }}">@csrf
                                <button class="rounded-md bg-white/10 px-3 py-1 font-medium hover:bg-white/20">Quitter cet espace</button>
                            </form>
                        </div>
                    </div>
                @endif

                <x-trial-banner />

                @isset($header)
                    <header class="border-b border-slate-200/80 bg-white">
                        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <x-flash />

                <main>
                    {{ $slot }}
                </main>
            </div>
        </div>
        <x-install-hint />
    </body>
</html>
