<x-app-layout title="{{ $workspace->name }} | {{ $brand['name'] }}">
    <x-slot name="header">
        <a href="{{ route('admin.workspaces.index') }}" class="text-sm text-slate-500 hover:text-brand-700">Espaces clients</a>
        <div class="mt-1">
            <x-page-header :title="$workspace->name" :subtitle="'Offre '.$workspace->planModel()?->name.' : '.$workspace->statusLabel().($workspace->plan_ends_at ? ', échéance le '.$workspace->plan_ends_at->format('d/m/Y') : '')">
                <x-slot name="actions">
                    <form method="POST" action="{{ route('admin.workspaces.enter', $workspace) }}">@csrf
                        <button class="btn-primary">Entrer dans l'espace</button>
                    </form>
                </x-slot>
            </x-page-header>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">

        @if ($workspace->is_suspended)
            <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900">
                Cet espace est suspendu{{ $workspace->suspended_reason ? " : ".$workspace->suspended_reason : "" }}. Ses assistants ne répondent plus.
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([['Réponses ce mois-ci', 'messages'], ['Assistants', 'bots'], ['Sources', 'sources']] as [$label, $key])
                @php $pct = min(100, (int) round(100 * $usage[$key]['used'] / max(1, $usage[$key]['limit']))); @endphp
                <div class="surface p-5">
                    <p class="text-sm text-slate-600">{{ $label }}</p>
                    <p class="font-display text-3xl font-bold text-brand-950">{{ $usage[$key]['used'] }} <span class="text-base font-normal text-slate-500">/ {{ $usage[$key]['limit'] }}</span></p>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $pct >= 90 ? 'bg-red-500' : 'bg-brand-500' }}" style="width: {{ $pct }}%"></div></div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-2">

            {{-- Abonnement et paiement --}}
            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold">Enregistrer un paiement</h2>
                <p class="text-sm text-slate-600">Après réception d'un paiement Mobile Money, d'un virement ou d'espèces : l'offre est activée et la période prolongée.</p>
                <form method="POST" action="{{ route('admin.payments.store', $workspace) }}" class="space-y-4">
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="pay-plan" value="Offre" />
                            <select id="pay-plan" name="plan" class="field">
                                @foreach ($plans->reject(fn ($p) => $p->isFree()) as $p)
                                    <option value="{{ $p->slug }}" @selected($workspace->plan === $p->slug)>{{ $p->name }} ({{ $p->formattedPrice($workspace->currency) }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="pay-months" value="Durée couverte (mois)" />
                            <input id="pay-months" name="period_months" type="number" min="1" max="24" value="1" required class="field">
                        </div>
                        <div>
                            <x-input-label for="pay-amount" value="Montant reçu" />
                            <input id="pay-amount" name="amount" type="number" min="0" required class="field" value="{{ $plans->firstWhere('slug', $workspace->plan)?->priceIn($workspace->currency) ?: '' }}">
                        </div>
                        <div>
                            <x-input-label for="pay-currency" value="Devise du paiement" />
                            <select id="pay-currency" name="currency" class="field">
                                @foreach (\App\Support\Currency::ALL as $code => $currency)
                                    <option value="{{ $code }}" @selected($workspace->currency === $code)>{{ $currency['name'] }} ({{ $currency['symbol'] }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="pay-method" value="Moyen de paiement" />
                            <select id="pay-method" name="method" class="field">
                                @foreach ($methods as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="pay-ref" value="Référence" />
                            <input id="pay-ref" name="reference" class="field" placeholder="N° de transaction">
                        </div>
                        <div>
                            <x-input-label for="pay-date" value="Date du paiement" />
                            <input id="pay-date" name="paid_at" type="date" class="field" value="{{ now()->format('Y-m-d') }}">
                        </div>
                    </div>
                    <button class="btn-primary">Enregistrer et activer</button>
                </form>
            </section>

            <section class="surface space-y-5 p-6">
                @php $pack = config('platform.billing.wa_pack'); $packCode = $workspace->currency; @endphp
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-display text-lg font-bold">Recharger des messages WhatsApp</h2>
                    <x-badge tone="brand">Crédit : {{ number_format($workspace->wa_credit, 0, ',', "\u{202F}") }} messages</x-badge>
                </div>
                <p class="text-sm text-slate-600">Le client paie par Mobile Money : vous enregistrez le paiement et les messages s'ajoutent à son crédit. Ils servent après le volume inclus dans son offre.</p>
                <form method="POST" action="{{ route('admin.wallet.topup', $workspace) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <div><x-input-label for="topup-messages" value="Messages ajoutés" /><input id="topup-messages" name="messages" type="number" min="1" required class="field" value="{{ $pack['messages'] }}"></div>
                    <div><x-input-label for="topup-amount" value="Montant reçu ({{ \App\Support\Currency::symbol($packCode) }})" /><input id="topup-amount" name="amount" type="number" min="0" required class="field" value="{{ $pack['prices'][$packCode] ?? $pack['prices']['XOF'] }}"></div>
                    <input type="hidden" name="currency" value="{{ $packCode }}">
                    <div>
                        <x-input-label for="topup-method" value="Moyen de paiement" />
                        <select id="topup-method" name="method" class="field">@foreach ($methods as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach</select>
                    </div>
                    <div><x-input-label for="topup-ref" value="Référence" /><input id="topup-ref" name="reference" class="field" placeholder="N° de transaction"></div>
                    <div class="sm:col-span-2"><button class="btn-primary">Enregistrer et créditer</button></div>
                </form>
            </section>
            <section class="surface space-y-4 p-6">
                <h2 class="font-display text-lg font-bold">Options à la carte</h2>
                <p class="text-sm text-slate-600">Une option achetée en plus de l'offre. Elle est aussi activée quand vous approuvez la demande du client (Demandes d'offre).</p>
                @foreach (config('platform.billing.addons') as $key => $addon)
                    @php $included = (bool) $workspace->planModel()?->feature($key); $on = $workspace->hasAddon($key); @endphp
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4">
                        <div class="min-w-0">
                            <p class="font-semibold text-brand-950">{{ $addon['name'] }}</p>
                            <p class="text-sm text-slate-500">{{ $included ? 'Déjà incluse dans son offre.' : ($on ? 'Activée à la carte.' : 'Non activée.') }}</p>
                        </div>
                        <form method="POST" action="{{ route('admin.workspaces.addon', $workspace) }}">
                            @csrf
                            <input type="hidden" name="addon" value="{{ $key }}">
                            <input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}">
                            <button class="btn-outline" @disabled($included)>{{ $on ? 'Retirer' : 'Activer' }}</button>
                        </form>
                    </div>
                @endforeach
            </section>
            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold">Changer l'offre sans paiement</h2>
                <p class="text-sm text-slate-600">Geste commercial, essai ou correction. Sans date de fin, une offre payante reste active ; l'offre gratuite reçoit la durée d'essai par défaut.</p>
                <form method="POST" action="{{ route('admin.workspaces.plan', $workspace) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf @method('PUT')
                    <div>
                        <x-input-label for="chg-plan" value="Offre" />
                        <select id="chg-plan" name="plan" class="field">
                            @foreach ($plans as $p) <option value="{{ $p->slug }}" @selected($workspace->plan === $p->slug)>{{ $p->name }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="chg-end" value="Jusqu'au (facultatif)" />
                        <input id="chg-end" name="ends_at" type="date" class="field" value="{{ $workspace->plan_ends_at?->format('Y-m-d') }}">
                    </div>
                    <div class="sm:col-span-2"><button class="btn-outline">Appliquer</button></div>
                </form>

                <div class="border-t border-slate-100 pt-5">
                    <h3 class="font-display font-bold">{{ $workspace->is_suspended ? 'Réactiver l\'espace' : 'Suspendre l\'espace' }}</h3>
                    <form method="POST" action="{{ route('admin.workspaces.suspend', $workspace) }}" class="mt-3 flex flex-wrap items-end gap-3">
                        @csrf
                        @unless ($workspace->is_suspended)
                            <div class="min-w-[12rem] flex-1">
                                <x-input-label for="reason" value="Motif (facultatif)" />
                                <input id="reason" name="reason" class="field" placeholder="Impayé, abus, demande du client…">
                            </div>
                        @endunless
                        <button class="{{ $workspace->is_suspended ? 'btn-primary' : 'btn-danger' }}">{{ $workspace->is_suspended ? 'Réactiver' : 'Suspendre' }}</button>
                    </form>
                </div>
            </section>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Utilisateurs --}}
            <section class="surface">
                <div class="border-b border-slate-100 px-6 py-4"><h2 class="font-display text-lg font-bold">Utilisateurs</h2></div>
                <div class="divide-y divide-slate-100">
                    @foreach ($users as $user)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm">
                            <div>
                                <p class="font-medium text-brand-950">{{ $user->name }} @unless ($user->is_active) <x-badge tone="red">Désactivé</x-badge> @endunless</p>
                                <p class="text-slate-600">{{ $user->email }}</p>
                            </div>
                            <div class="flex gap-3">
                                <form method="POST" action="{{ route('admin.users.reset', $user) }}" onsubmit="return confirm('Générer un nouveau mot de passe provisoire ?')">@csrf
                                    <button class="font-medium text-brand-600 hover:text-brand-800">Réinitialiser le mot de passe</button>
                                </form>
                                <form method="POST" action="{{ route('admin.users.toggle', $user) }}">@csrf
                                    <button class="font-medium {{ $user->is_active ? 'text-red-600 hover:text-red-800' : 'text-emerald-700 hover:text-emerald-900' }}">{{ $user->is_active ? 'Désactiver' : 'Réactiver' }}</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('admin.workspace-users.store', $workspace) }}" class="grid gap-3 border-t border-slate-100 bg-slate-50 p-6 sm:grid-cols-[1fr_1fr_auto]">
                    @csrf
                    <input name="name" required class="field !mt-0" placeholder="Nom">
                    <input name="email" type="email" required class="field !mt-0" placeholder="E-mail">
                    <button class="btn-primary">Ajouter</button>
                </form>
            </section>

            {{-- Activité dans l'application (mesure d'audience) --}}
            <section class="surface p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-display text-lg font-bold">Activité dans l'application</h2>
                        <p class="mt-0.5 text-sm text-slate-600">30 derniers jours. Visible de l'équipe seulement.</p>
                    </div>
                    @php $tone = ['good' => 'green', 'ok' => 'blue', 'warn' => 'amber', 'bad' => 'red'][$activity['health']['tone']]; @endphp
                    <x-badge :tone="$tone">Santé {{ $activity['health']['score'] }} sur 100 : {{ $activity['health']['label'] }}</x-badge>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-slate-500">Visites</dt><dd class="mt-1 font-display text-2xl font-bold text-brand-950">{{ $activity['sessions'] }}</dd></div>
                    <div><dt class="text-slate-500">Jours actifs</dt><dd class="mt-1 font-display text-2xl font-bold text-brand-950">{{ $activity['active_days'] }}</dd></div>
                    <div><dt class="text-slate-500">Temps passé</dt><dd class="mt-1 font-display text-2xl font-bold text-brand-950">{{ \App\Support\StatsFormat::duration($activity['seconds']) }}</dd></div>
                    <div><dt class="text-slate-500">Dernière visite</dt><dd class="mt-1 text-base font-semibold text-brand-950">{{ $activity['last_seen'] ? $activity['last_seen']->locale('fr')->diffForHumans() : 'jamais' }}</dd></div>
                </dl>
                @if (count($activity['pages']) || count($activity['actions']))
                    <div class="mt-5 grid gap-6 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-medium text-slate-500">Pages les plus visitées</p>
                            <ul class="mt-2 space-y-1 text-sm">@foreach ($activity['pages'] as $page)<li class="flex justify-between gap-3"><span class="truncate text-slate-700">{{ $page['name'] }}</span><span class="tabular-nums text-slate-500">{{ $page['count'] }}</span></li>@endforeach</ul>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500">Actions faites</p>
                            <ul class="mt-2 space-y-1 text-sm">@forelse ($activity['actions'] as $action)<li class="flex justify-between gap-3"><span class="truncate text-slate-700">{{ $action['name'] }}</span><span class="tabular-nums text-slate-500">{{ $action['count'] }}</span></li>@empty<li class="text-slate-500">Aucune action enregistrée.</li>@endforelse</ul>
                        </div>
                    </div>
                @else
                    <p class="mt-4 text-sm text-slate-600">Aucune activité mesurée sur la période : ce client ne s'est pas connecté, ou la mesure n'existait pas encore.</p>
                @endif
            </section>

            {{-- Assistants --}}
            <section class="surface">
                <div class="border-b border-slate-100 px-6 py-4"><h2 class="font-display text-lg font-bold">Assistants</h2></div>
                @forelse ($bots as $bot)
                    <div class="flex items-center justify-between gap-3 px-6 py-3 text-sm {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                        <div>
                            <p class="font-medium text-brand-950">{{ $bot->name }}</p>
                            <p class="text-slate-600">{{ $bot->sources_count }} source(s)</p>
                        </div>
                        <a href="{{ route('demo', $bot->public_key) }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:text-brand-800">Démonstration</a>
                    </div>
                @empty
                    <p class="px-6 py-10 text-center text-sm text-slate-600">Aucun assistant. Entrez dans l'espace pour en créer un à la place du client.</p>
                @endforelse
            </section>
        </div>

        {{-- Fiche --}}
        <section class="surface p-6">
            <h2 class="font-display text-lg font-bold">Informations</h2>
            <form method="POST" action="{{ route('admin.workspaces.update', $workspace) }}" class="mt-4 grid gap-4 sm:grid-cols-3">
                @csrf @method('PUT')
                <div><x-input-label for="ws-name" value="Nom" /><input id="ws-name" name="name" required class="field" value="{{ old('name', $workspace->name) }}"></div>
                <div><x-input-label for="ws-country" value="Pays" /><input id="ws-country" name="country" class="field" value="{{ old('country', $workspace->country) }}"></div>
                <div><x-input-label for="ws-phone" value="Téléphone" /><input id="ws-phone" name="phone" class="field" value="{{ old('phone', $workspace->phone) }}"></div>
                <div>
                    <x-input-label for="ws-currency" value="Devise du compte" />
                    <select id="ws-currency" name="currency" class="field">
                        @foreach (\App\Support\Currency::ALL as $code => $currency)
                            <option value="{{ $code }}" @selected(old('currency', $workspace->currency) === $code)>{{ $currency['name'] }} ({{ $currency['symbol'] }})</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Prix, paiements et facturation de ce client.</p>
                </div>
                <div class="sm:col-span-3"><x-input-label for="ws-notes" value="Notes internes" /><textarea id="ws-notes" name="notes" rows="3" class="field">{{ old('notes', $workspace->notes) }}</textarea></div>
                <div class="sm:col-span-3"><button class="btn-outline">Enregistrer</button></div>
            </form>
        </section>

        {{-- Paiements --}}
        <section class="surface">
            <div class="border-b border-slate-100 px-6 py-4"><h2 class="font-display text-lg font-bold">Historique des paiements</h2></div>
            @if ($payments->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-slate-600">Aucun paiement enregistré.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-slate-500"><tr><th class="px-6 py-3 font-medium">Date</th><th class="px-6 py-3 font-medium">Offre</th><th class="px-6 py-3 font-medium">Montant</th><th class="px-6 py-3 font-medium">Moyen</th><th class="px-6 py-3 font-medium">Période</th><th class="px-6 py-3 font-medium">Saisi par</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($payments as $payment)
                                <tr>
                                    <td class="px-6 py-3">{{ $payment->paid_at?->format('d/m/Y') }}</td>
                                    <td class="px-6 py-3">{{ $plans->firstWhere('slug', $payment->plan)?->name ?? $payment->plan }}</td>
                                    <td class="px-6 py-3 font-medium">{{ $payment->formattedAmount() }}</td>
                                    <td class="px-6 py-3">{{ $payment->methodLabel() }}@if ($payment->reference) <span class="text-slate-500">({{ $payment->reference }})</span>@endif</td>
                                    <td class="px-6 py-3">{{ $payment->period_start?->format('d/m/Y') }} au {{ $payment->period_end?->format('d/m/Y') }}</td>
                                    <td class="px-6 py-3">{{ $payment->recorder?->name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
