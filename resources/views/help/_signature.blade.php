{{--
    Signature de Kouma, au pied de chaque guide : la marque, une ligne safran, la mention « Tout droit de Kouma »,
    puis l'équipe. La mention est réglée dans App\Support\Guides::SIGNATURE (une seule fois pour le site et les PDF).
--}}
@props(['tone' => 'light'])
@php $dark = $tone === 'dark'; @endphp
<div {{ $attributes->merge(['class' => 'text-center']) }}>
    <div class="inline-flex flex-col items-center">
        <x-logo :tone="$dark ? 'light' : 'dark'" size="lg" />
        <span class="mt-4 h-1 w-16 rounded-full bg-accent-500"></span>
        <p class="mt-4 font-display text-sm font-semibold tracking-wide {{ $dark ? 'text-accent-300' : 'text-brand-700' }}">{{ \App\Support\Guides::SIGNATURE }}</p>
        <p class="mt-1 text-sm {{ $dark ? 'text-white/75' : 'text-slate-600' }}">L'équipe {{ $brand['name'] ?? 'Kouma' }} · {{ $brand['tagline'] ?? '' }}</p>
        <p class="mt-1 text-xs {{ $dark ? 'text-white/50' : 'text-slate-500' }}">{{ preg_replace('#^https?://#', '', rtrim($brand['url'] ?? url('/'), '/')) }}@if (! empty($brand['email'])) · {{ $brand['email'] }}@endif</p>
    </div>
</div>
