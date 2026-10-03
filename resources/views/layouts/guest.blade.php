<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if ($indexable && $description)
            <x-seo :title="$title ?? $brand['name']" :description="$description" />
        @else
            <title>{{ $title ?? $brand['name'] }}</title>
            <meta name="robots" content="noindex, nofollow">
        @endif
        <x-pwa-meta />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=unbounded:500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />

        <script>try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches&&document.referrer&&new URL(document.referrer).origin===location.origin){document.documentElement.classList.add('page-arriving')}}catch(e){}</script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            {{-- Panneau de marque : visible sur grand ecran --}}
            <div class="wax relative hidden flex-col justify-between gap-8 overflow-hidden p-10 text-white lg:flex" data-ripple-field>
                <a href="{{ route('home') }}" class="relative"><x-logo tone="light" size="lg" /></a>
                <div class="relative max-w-md">
                    <p class="font-display text-4xl font-bold leading-[1.1]">Vos clients écrivent sur WhatsApp à toute heure. Votre assistant répond.</p>
                    <p class="mt-5 text-lg text-white/80">Donnez-lui vos tarifs, vos photos, votre site. En dix minutes, il répond sur WhatsApp, et sur votre site si vous en avez un.</p>
                </div>
                <div class="relative mx-auto hidden w-[19rem] max-w-full [@media(min-height:860px)]:block"><x-chat-demo /></div>
                <p class="relative text-sm text-white/60">{{ $brand['name'] }} : {{ config('brand.meaning') }}.</p>
            </div>

            <div class="flex flex-col justify-center px-6 py-10 sm:px-12">
                <a href="{{ route('home') }}" class="mb-8 lg:hidden"><x-logo /></a>
                <div class="mx-auto w-full max-w-md">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </body>
</html>
