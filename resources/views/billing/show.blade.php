<x-app-layout title="Abonnement | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Mon abonnement" subtitle="Votre offre, votre consommation et vos paiements." />
    </x-slot>

    @php
        $ends = $workspace->plan_ends_at;
        $status = $workspace->subscription_status;
    @endphp

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">

        {{-- Offre actuelle --}}
        <section class="surface grid gap-6 p-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <div>
                <p class="text-sm text-slate-600">Offre actuelle</p>
                <p class="mt-1 flex items-center gap-3 font-display text-3xl font-bold text-brand-950">
                    {{ $plan?->name ?? 'Gratuite' }}
                    @if ($workspace->trialExpired()) <x-badge tone="red">Essai terminé</x-badge>
                    @elseif ($workspace->onTrial()) <x-badge tone="amber">Essai gratuit</x-badge>
                    @elseif ($status === 'past_due') <x-badge tone="red">À renouveler</x-badge>
                    @elseif ($status === 'active' && $ends) <x-badge tone="green">Active</x-badge>
                    @elseif ($plan?->isFree()) <x-badge>Gratuite</x-badge> @endif
                </p>
                <p class="mt-2 text-sm text-slate-600">
                    @if ($workspace->trialExpired())
                        Votre essai gratuit s'est terminé le {{ $ends->format('d/m/Y') }}. Votre assistant est en pause : choisissez une offre ci-dessous pour le réactiver. Vos assistants et vos données sont conservés.
                    @elseif ($workspace->onTrial())
                        Votre essai gratuit dure jusqu'au {{ $ends->format('d/m/Y') }} ({{ $ends->diffForHumans() }}). Choisissez une offre avant cette date pour ne pas interrompre votre assistant.
                    @elseif ($ends)
                        @if ($ends->isPast())
                            Échue depuis le {{ $ends->format('d/m/Y') }}. Renouvelez avant le {{ $ends->copy()->addDays((int) config('platform.billing.grace_days'))->format('d/m/Y') }} pour garder vos avantages.
                        @else
                            Valable jusqu'au {{ $ends->format('d/m/Y') }} ({{ $ends->diffForHumans() }}).
                        @endif
                    @endif
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (array_values(array_filter([['Réponses ce mois-ci', 'messages'], $workspace->hasFeature('whatsapp') ? ['Messages WhatsApp', 'whatsapp'] : null, $workspace->hasFeature('voice') ? ['Messages vocaux', 'voice'] : null, ['Assistants', 'bots'], ['Sources', 'sources']])) as [$label, $key])
                    @php $pct = min(100, (int) round(100 * $usage[$key]['used'] / max(1, $usage[$key]['limit']))); @endphp
                    <div>
                        <p class="text-sm text-slate-600">{{ $label }}</p>
                        <p class="font-display text-xl font-bold text-brand-950">{{ $usage[$key]['used'] }} <span class="text-sm font-normal text-slate-500">/ {{ $usage[$key]['limit'] }}</span></p>
                        @if ($key === 'whatsapp' && $usage[$key]['credit'] > 0)
                            <p class="mt-0.5 text-xs font-medium text-accent-700">+ {{ number_format($usage[$key]['credit'], 0, ',', "\u{202F}") }} messages de crédit</p>
                        @endif
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="bar-fill h-full rounded-full {{ $pct >= 90 ? 'bg-red-500' : 'bg-brand-500' }}" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        @if ($pending)
            <div class="rounded-xl border border-brand-200 bg-brand-50 px-5 py-4 text-sm text-brand-900">
                Demande en cours : <strong>{{ $pending->label() }}</strong>. Payez selon les indications ci-dessous : elle est activée dès réception de votre paiement.
            </div>
        @endif

        {{-- Offres --}}
        <section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-display text-xl font-bold">Choisir une offre</h2>
                <x-account-currency />
            </div>
            <div class="mt-4 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($plans as $p)
                    @php $current = $p->slug === $workspace->plan; @endphp
                    <article class="surface flex flex-col p-6 {{ $p->is_highlighted ? 'ring-2 ring-brand-500' : '' }}">
                        <div class="flex items-center justify-between">
                            <h3 class="font-display text-lg font-bold">{{ $p->name }}</h3>
                            @if ($p->is_highlighted) <x-badge tone="brand">Populaire</x-badge> @endif
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ $p->tagline }}</p>
                        <p class="mt-4 font-display text-3xl font-bold text-brand-950">{{ $p->formattedPrice() }}</p>
                        @if ($p->periodLabel() !== '') <p class="text-xs text-slate-500">{{ $p->periodLabel() }}</p> @endif

                        <ul class="mt-4 flex-1 space-y-1.5 text-sm text-slate-700">
                            <li>{{ $p->limit('bots') }} assistant(s)</li>
                            <li>{{ number_format($p->limit('messages_per_month'), 0, ',', ' ') }} réponses par mois</li>
                            <li>{{ $p->limit('sources') }} sources, {{ $p->limit('pages_per_crawl') }} pages par site</li>
                            <li>{{ $p->limit('members') }} utilisateur(s)</li>
                            @foreach (\App\Models\Plan::featureFields() as $f)
                                <li class="{{ $p->feature($f['key']) ? '' : 'text-slate-400 line-through' }}">{{ $f['label'] }}</li>
                            @endforeach
                        </ul>

                        <div class="mt-5">
                            @if ($current)
                                <span class="btn-outline w-full cursor-default opacity-70">Offre actuelle</span>
                            @elseif ($p->isFree())
                                <span class="block text-center text-xs text-slate-500">{{ $p->hasTrial() ? 'Offre d\'essai de '.$p->trial_days.' jours' : 'Offre gratuite' }}</span>
                            @else
                                <form method="POST" action="{{ route('billing.request') }}">
                                    @csrf <input type="hidden" name="plan" value="{{ $p->slug }}">
                                    <button class="{{ $p->is_highlighted ? 'btn-primary' : 'btn-outline' }} w-full">
                                        {{ $current || ($pending && $pending->plan === $p->slug) ? 'Demande envoyée' : 'Choisir '.$p->name }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- Comment payer --}}
        <section class="surface p-6">
            <h2 class="font-display text-lg font-bold">Comment payer</h2>
            <div class="mt-3 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ $instructions ?: 'Choisissez une offre : notre équipe vous indique le moyen de paiement (Mobile Money ou virement) et active votre abonnement dès réception.' }}</div>
        </section>

        {{-- Historique --}}
        <section class="surface">
            <div class="border-b border-slate-100 px-6 py-4"><h2 class="font-display text-lg font-bold">Historique des paiements</h2></div>
            @if ($payments->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-slate-600">Aucun paiement enregistré pour le moment.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-slate-500">
                            <tr><th class="px-6 py-3 font-medium">Date</th><th class="px-6 py-3 font-medium">Offre</th><th class="px-6 py-3 font-medium">Montant</th><th class="px-6 py-3 font-medium">Moyen</th><th class="px-6 py-3 font-medium">Période couverte</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($payments as $payment)
                                <tr>
                                    <td class="px-6 py-3">{{ $payment->paid_at?->format('d/m/Y') }}</td>
                                    <td class="px-6 py-3">{{ $plans->firstWhere('slug', $payment->plan)?->name ?? $payment->plan }}</td>
                                    <td class="px-6 py-3 font-medium">{{ $payment->formattedAmount() }}</td>
                                    <td class="px-6 py-3">{{ $payment->methodLabel() }}@if ($payment->reference) <span class="text-slate-500">({{ $payment->reference }})</span>@endif</td>
                                    <td class="px-6 py-3">{{ $payment->period_start?->format('d/m/Y') }} au {{ $payment->period_end?->format('d/m/Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
