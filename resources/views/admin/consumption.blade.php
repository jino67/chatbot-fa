<x-app-layout title="Consommation | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Consommation en direct" subtitle="Ce que chaque client consomme, calculé à chaque message : réponses IA, messages WhatsApp, vocal, coût estimé et marge.">
            <x-slot name="actions">
                <span class="inline-flex items-center gap-2 rounded-full bg-feuille-500/10 px-3 py-1.5 text-xs font-semibold text-feuille-600" x-data x-cloak>
                    <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-feuille-500 opacity-60"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-feuille-500"></span></span>
                    En direct
                </span>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div x-data="consumption(@js($report), '{{ route('admin.consumption.live') }}')" x-init="start()" class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">

        {{-- Portefeuille et totaux --}}
        <div class="stagger grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="surface-link p-5" :class="r.wallet.low && 'border-red-300 bg-red-50/50'">
                <p class="text-sm text-slate-600">Solde Twilio (portefeuille)</p>
                <template x-if="r.wallet.ok">
                    <div>
                        <p class="mt-2 font-display text-3xl font-bold" :class="r.wallet.low ? 'text-red-600' : 'text-brand-950'" x-text="usd(r.wallet.balance)"></p>
                        <p class="mt-1 text-xs text-slate-500">
                            <span x-show="r.wallet.runway_days !== null">Environ <strong x-text="r.wallet.runway_days"></strong> jours d'autonomie au rythme des 7 derniers jours.</span>
                            <span x-show="r.wallet.runway_days === null">Pas encore de dépense récente.</span>
                        </p>
                    </div>
                </template>
                <template x-if="!r.wallet.ok">
                    <div>
                        <p class="mt-2 text-sm font-medium text-slate-700" x-text="r.wallet.error"></p>
                        <a href="{{ route('admin.settings.edit') }}#whatsapp" class="mt-2 inline-block text-sm font-semibold text-brand-600 hover:text-brand-800">Renseigner le compte</a>
                    </div>
                </template>
                <form method="POST" action="{{ route('admin.consumption.wallet') }}" class="mt-3">@csrf<button class="text-xs font-medium text-brand-600 hover:text-brand-800">Relire le solde</button></form>
            </div>

            <div class="surface-link p-5">
                <p class="text-sm text-slate-600">Coût du mois (estimé)</p>
                <p class="mt-2 font-display text-3xl font-bold text-brand-950" x-text="usd(r.totals.cost_month)"></p>
                <p class="mt-1 text-xs text-slate-500"><span x-text="fcfa(r.totals.cost_month_xof)"></span>, dont <span x-text="usd(r.totals.cost_today)"></span> aujourd'hui.</p>
            </div>

            <div class="surface-link p-5">
                <p class="text-sm text-slate-600">Marge du mois (estimée)</p>
                <p class="mt-2 font-display text-3xl font-bold" :class="r.totals.margin_month < 0 ? 'text-red-600' : 'text-brand-950'" x-text="r.totals.margin_pct === null ? 'n.d.' : r.totals.margin_pct + ' %'"></p>
                <p class="mt-1 text-xs text-slate-500"><span x-text="usd(r.totals.margin_month)"></span> sur <span x-text="usd(r.totals.revenue_month)"></span> d'abonnements.</p>
            </div>

            <div class="surface-link p-5">
                <p class="text-sm text-slate-600">Aujourd'hui</p>
                <p class="mt-2 font-display text-3xl font-bold text-brand-950"><span x-text="r.totals.wa_today"></span> <span class="text-base font-normal text-slate-500">WhatsApp</span></p>
                <p class="mt-1 text-xs text-slate-500"><span x-text="r.totals.ai_today"></span> réponses de l'IA.</p>
            </div>
        </div>

        {{-- Recommandation : Meta direct coute moins cher --}}
        <div x-show="r.savings.twilio_messages > 0" x-cloak class="rounded-2xl rounded-bl-md border border-accent-300 bg-accent-50 p-5 text-sm text-brand-950">
            <p class="font-semibold">Économie possible : faire passer les canaux Twilio par Meta direct</p>
            <p class="mt-1 text-slate-700">
                <span x-text="r.savings.twilio_messages"></span> messages sont passés par Twilio ce mois-ci, soit <strong x-text="usd(r.savings.month_so_far)"></strong> de frais d'intermédiaire que Meta direct ne facture pas
                (environ <strong x-text="usd(r.savings.projected_month)"></strong> sur le mois entier au rythme actuel). Les frais de catégorie de Meta restent les mêmes.
            </p>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,1fr)]">
            {{-- Clients --}}
            <section class="surface overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 class="font-display text-lg font-bold">Clients, ce mois-ci</h2>
                    <p class="text-xs text-slate-500">Mis à jour <span x-text="ago()"></span></p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-medium">Client</th>
                                <th class="px-3 py-3 font-medium">Réponses IA</th>
                                <th class="px-3 py-3 font-medium">WhatsApp</th>
                                <th class="px-3 py-3 font-medium">Coût</th>
                                <th class="px-3 py-3 font-medium">Revenu</th>
                                <th class="px-5 py-3 font-medium">Marge</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="row in r.rows" :key="row.id">
                                <tr class="align-top transition hover:bg-brand-50/40">
                                    <td class="px-5 py-3">
                                        <a :href="'{{ url('/admin/workspaces') }}/' + row.id" class="font-semibold text-brand-950 hover:text-brand-700" x-text="row.name"></a>
                                        <p class="text-xs text-slate-500"><span x-text="row.plan"></span> · <span x-text="row.status"></span></p>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            <span x-show="row.flags.includes('wa_quota')" class="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700">Volume WhatsApp presque atteint</span>
                                            <span x-show="row.flags.includes('wa_credit')" class="rounded-full bg-accent-100 px-2 py-0.5 text-[11px] font-medium text-accent-700">Consomme son crédit</span>
                                            <span x-show="row.flags.includes('margin')" class="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700">Coût élevé</span>
                                            <span x-show="row.flags.includes('free_cost')" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">Offre gratuite coûteuse</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="font-medium" x-text="row.ai.used"></span><span class="text-slate-400"> / <span x-text="row.ai.limit"></span></span>
                                        <div class="mt-1.5 h-1.5 w-24 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full transition-all duration-700" :class="pct(row.ai.used, row.ai.limit) >= 90 ? 'bg-red-500' : 'bg-brand-500'" :style="'width:' + pct(row.ai.used, row.ai.limit) + '%'"></div></div>
                                    </td>
                                    <td class="px-3 py-3">
                                        <template x-if="row.wa.allowance > 0 || row.wa.used > 0">
                                            <div>
                                                <span class="font-medium" x-text="row.wa.used"></span><span class="text-slate-400"> / <span x-text="row.wa.allowance"></span></span>
                                                <span x-show="row.wa.credit > 0" class="ml-1 rounded-full bg-accent-100 px-1.5 py-0.5 text-[11px] font-semibold text-accent-700" x-text="'+' + row.wa.credit + ' crédit'"></span>
                                                <div class="mt-1.5 h-1.5 w-24 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full transition-all duration-700" :class="pct(row.wa.used, row.wa.allowance) >= 90 ? 'bg-red-500' : 'bg-feuille-500'" :style="'width:' + pct(row.wa.used, row.wa.allowance) + '%'"></div></div>
                                                <p class="mt-1 text-[11px] text-slate-500"><span x-text="row.wa.in"></span> reçus · <span x-text="row.wa.out"></span> envoyés · <span x-text="row.wa.template"></span> modèles</p>
                                            </div>
                                        </template>
                                        <span x-show="row.wa.allowance === 0 && row.wa.used === 0" class="text-slate-400">Non inclus</span>
                                    </td>
                                    <td class="px-3 py-3"><span class="font-medium" x-text="usd(row.cost_month, 3)"></span><p class="text-[11px] text-slate-500"><span x-text="usd(row.cost_today, 3)"></span> aujourd'hui</p></td>
                                    <td class="px-3 py-3" x-text="usd(row.revenue)"></td>
                                    <td class="px-5 py-3"><span class="font-semibold" :class="row.margin < 0 ? 'text-red-600' : 'text-feuille-600'" x-text="row.margin_pct === null ? 'n.d.' : row.margin_pct + ' %'"></span></td>
                                </tr>
                            </template>
                            <tr x-show="r.rows.length === 0"><td colspan="6" class="px-5 py-10 text-center text-slate-500">Aucun client pour le moment.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Flux --}}
            <aside class="space-y-6">
                <section class="surface p-5">
                    <h2 class="font-display text-lg font-bold">Répartition WhatsApp</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex items-center justify-between"><dt class="text-slate-600">Meta direct</dt><dd class="font-medium"><span x-text="r.providers.meta.messages"></span> messages · <span x-text="usd(r.providers.meta.cost, 3)"></span></dd></div>
                        <div class="flex items-center justify-between"><dt class="text-slate-600">Twilio</dt><dd class="font-medium"><span x-text="r.providers.twilio.messages"></span> messages · <span x-text="usd(r.providers.twilio.cost, 3)"></span></dd></div>
                    </dl>
                </section>

                <section class="surface p-5">
                    <h2 class="font-display text-lg font-bold">Derniers événements</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <template x-for="(event, i) in r.feed" :key="event.at + i">
                            <li class="flex items-start justify-between gap-3">
                                <div class="min-w-0"><p class="truncate font-medium" x-text="event.workspace"></p><p class="text-xs text-slate-500" x-text="event.label + (event.provider ? ' · ' + event.provider : '')"></p></div>
                                <span class="shrink-0 text-xs font-medium text-slate-600" x-text="usd(event.cost, 4)"></span>
                            </li>
                        </template>
                        <li x-show="r.feed.length === 0" class="text-slate-500">Rien pour l'instant.</li>
                    </ul>
                </section>
            </aside>
        </div>

        <p class="text-xs text-slate-500">Coûts estimés d'après les tarifs unitaires réglés dans <a href="{{ route('admin.settings.edit') }}#whatsapp" class="font-medium text-brand-600 hover:text-brand-800">Paramètres, WhatsApp et coûts</a> (Twilio : frais par message ; Meta : tarif par catégorie ; IA : tarif du modèle). Le solde Twilio, lui, est lu en direct chez Twilio.</p>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('consumption', (initial, url) => ({
                r: initial,
                now: Date.now(),
                loadedAt: Date.now(),
                start() {
                    setInterval(() => { this.now = Date.now(); }, 1000);
                    setInterval(() => this.refresh(), 8000);
                },
                async refresh() {
                    if (document.hidden) return;
                    try {
                        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                        if (res.ok) { this.r = await res.json(); this.loadedAt = Date.now(); }
                    } catch (e) { /* on reessaie au prochain tour */ }
                },
                ago() { const s = Math.max(0, Math.round((this.now - this.loadedAt) / 1000)); return s < 3 ? 'à l\'instant' : 'il y a ' + s + ' s'; },
                pct(used, limit) { return limit > 0 ? Math.min(100, Math.round(100 * used / limit)) : 0; },
                usd(v, d = 2) { return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'USD', minimumFractionDigits: Math.min(d, 2), maximumFractionDigits: d }).format(v ?? 0); },
                fcfa(v) { return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(v ?? 0) + ' FCFA'; },
            }));
        });
    </script>
</x-app-layout>
