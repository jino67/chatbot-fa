<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? $brand['name'] }}</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            {{-- Panneau de marque : visible sur grand ecran --}}
            <div class="wax relative hidden flex-col justify-between p-10 text-white lg:flex">
                <a href="{{ route('home') }}"><x-logo tone="light" size="lg" /></a>
                <div class="max-w-md">
                    <p class="font-display text-4xl font-bold leading-[1.1]">Vos clients écrivent à toute heure. Votre assistant répond.</p>
                    <p class="mt-5 text-lg text-white/80">Donnez-lui vos tarifs, vos photos, votre site. En dix minutes, il répond sur votre site et sur WhatsApp.</p>
                </div>
                <p class="text-sm text-white/60">{{ $brand['name'] }} : {{ config('brand.meaning') }}.</p>
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
