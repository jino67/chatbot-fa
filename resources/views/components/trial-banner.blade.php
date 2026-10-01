{{-- Bandeau d'essai gratuit : décompte pendant l'essai, alerte une fois l'essai terminé (l'assistant est alors en pause). --}}
@php
    $workspace = auth()->user()?->workspace;
    $expired = $workspace?->trialExpired();
    $onTrial = $workspace?->onTrial();
@endphp

@if (($expired || $onTrial) && ! request()->routeIs('billing.*'))
    @if ($expired)
        <div class="border-b border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 sm:px-6 lg:px-8" role="alert">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                <span><strong class="font-semibold">Votre essai gratuit est terminé.</strong> Votre assistant ne répond plus à vos visiteurs. Vos données sont conservées : choisissez une offre pour le réactiver.</span>
                <a href="{{ route('billing.show') }}" class="btn-primary shrink-0 px-4 py-1.5">Choisir une offre</a>
            </div>
        </div>
    @else
        @php $days = max(0, (int) ceil(now()->diffInHours($workspace->plan_ends_at, false) / 24)); @endphp
        <div class="border-b border-accent-200 bg-accent-50 px-4 py-2.5 text-sm text-brand-950 sm:px-6 lg:px-8">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                <span>Essai gratuit : <strong class="font-semibold">{{ $days }} jour{{ $days > 1 ? 's' : '' }} restant{{ $days > 1 ? 's' : '' }}</strong>, jusqu'au {{ $workspace->plan_ends_at->format('d/m/Y') }}.</span>
                <a href="{{ route('billing.show') }}" class="font-semibold text-brand-700 underline decoration-brand-300 underline-offset-2 hover:text-brand-900">Voir les offres</a>
            </div>
        </div>
    @endif
@endif
