{{--
    Logo : le « o » de kouma est la marque elle-même, deux demi-cercles qui se répondent.
    Le mot est dessiné en Unbounded, dont les rondeurs prolongent le cercle. Au survol, la bouche s'ouvre.
--}}
@props(['tone' => 'dark', 'size' => 'md', 'wordmark' => true])
@php
    $text = ['sm' => 'text-lg', 'md' => 'text-xl', 'lg' => 'text-3xl', 'xl' => 'text-5xl'][$size] ?? 'text-xl';
    $ink = $tone === 'light' ? 'text-white' : 'text-brand-950';
    $name = strtolower($brand['name'] ?? 'kouma');
    // Le « o » de « kouma » est remplace par la marque ; un autre nom de marque s'ecrit tel quel, avec la marque devant.
    $split = str_contains($name, 'o') && $name === 'kouma';
@endphp
<span {{ $attributes->merge(['class' => 'logo group inline-flex items-center']) }} role="img" aria-label="{{ $brand['name'] ?? 'Kouma' }}">
    @if (! $wordmark)
        <x-mark :tone="$tone" class="h-9 w-9" />
    @elseif ($split)
        <span aria-hidden="true" class="font-display {{ $text }} font-semibold leading-none tracking-tight {{ $ink }} inline-flex items-center">k<x-mark :tone="$tone" class="mx-[0.04em] h-[0.92em] w-[0.92em] translate-y-[0.02em]" />uma</span>
    @else
        <span aria-hidden="true" class="inline-flex items-center gap-2">
            <x-mark :tone="$tone" class="h-[1.1em] w-[1.1em] {{ $text }}" />
            <span class="font-display {{ $text }} font-semibold leading-none tracking-tight {{ $ink }}">{{ $name }}</span>
        </span>
    @endif
</span>
