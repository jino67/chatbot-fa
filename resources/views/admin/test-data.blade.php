<x-app-layout title="Données de test | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Données de test" subtitle="Remettre à zéro les paiements simulés et les compteurs des espaces d'essai, quand la plateforme est prête à accueillir de vrais clients." />
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-5 px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            <p><strong>Cette action efface des données et ne se défait pas.</strong> Elle ne touche que les espaces que vous cochez, un par un. Avant l'effacement, une copie de ce qui disparaît est écrite dans le dossier <code>storage/app/backups</code> de l'application, et l'action est inscrite au Journal.</p>
            <p class="mt-2">Ne cochez jamais l'espace d'un vrai client : ses paiements sont la trace de ce qu'il a payé.</p>
        </div>

        @if ($rows === [])
            <div class="surface px-6 py-12 text-center text-sm text-slate-600">Aucun espace ne contient de paiement, de crédit, de consommation ni d'offre payante : il n'y a rien à remettre à zéro.</div>
        @else
            <form method="POST" action="{{ route('admin.test-data.reset') }}" class="space-y-5" onsubmit="return confirm('Remettre à zéro les espaces cochés ? Cette action ne se défait pas.')">
                @csrf

                <section class="surface overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead class="border-b border-slate-100 text-xs font-medium text-slate-500">
                            <tr>
                                <th class="px-4 py-3"><span class="sr-only">Choisir</span></th>
                                <th class="px-4 py-3">Espace</th>
                                <th class="px-4 py-3">Offre</th>
                                <th class="px-4 py-3">Paiements</th>
                                <th class="px-4 py-3">Crédit WhatsApp</th>
                                <th class="px-4 py-3">Mesures</th>
                                <th class="px-4 py-3">Demandes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php $w = $row['workspace']; @endphp
                                <tr class="border-b border-slate-50 align-top hover:bg-slate-50">
                                    <td class="px-4 py-3"><input type="checkbox" name="workspaces[]" value="{{ $w->id }}" id="ws{{ $w->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(in_array($w->id, array_map('intval', (array) old('workspaces', []))))></td>
                                    <td class="px-4 py-3">
                                        <label for="ws{{ $w->id }}" class="block cursor-pointer font-semibold text-brand-950">{{ $w->name }}</label>
                                        <span class="text-xs text-slate-500">Espace n° {{ $w->id }} · créé {{ $w->created_at?->diffForHumans() }}</span>
                                    </td>
                                    <td class="px-4 py-3">{{ $w->planModel()?->name ?? $w->plan }}@if ($w->plan_ends_at) <span class="block text-xs text-slate-500">jusqu'au {{ $w->plan_ends_at->format('d/m/Y') }}</span>@endif</td>
                                    <td class="px-4 py-3">
                                        {{ $row['payments'] }}
                                        @foreach ($row['paid'] as $currency => $total)
                                            <span class="block text-xs text-slate-500">{{ number_format($total, 0, ',', ' ') }} {{ $currency === 'XOF' ? 'FCFA' : $currency }}</span>
                                        @endforeach
                                        @if ($row['last_payment']) <span class="block text-xs text-slate-500">dernier : {{ $row['last_payment']->format('d/m/Y') }}</span> @endif
                                    </td>
                                    <td class="px-4 py-3">{{ number_format($row['credit'], 0, ',', ' ') }}</td>
                                    <td class="px-4 py-3">{{ number_format($row['usage'], 0, ',', ' ') }}</td>
                                    <td class="px-4 py-3">{{ $row['requests'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
                <x-input-error :messages="$errors->get('workspaces')" />

                <section class="surface space-y-3 p-6">
                    <h2 class="font-display text-lg font-bold">Que remettre à zéro ?</h2>
                    @foreach ($options as $key => [$label, $help])
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="options[]" value="{{ $key }}" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(in_array($key, (array) old('options', ['payments', 'credit', 'plan'])))>
                            <span><span class="font-medium">{{ $label }}</span><span class="block text-xs text-slate-500">{{ $help }}</span></span>
                        </label>
                    @endforeach
                    <x-input-error :messages="$errors->get('options')" />
                </section>

                <section class="surface space-y-3 p-6">
                    <label for="confirmation" class="text-sm font-medium">Pour confirmer, écrivez <strong>{{ $confirmation }}</strong></label>
                    <input id="confirmation" name="confirmation" class="field max-w-xs" autocomplete="off" value="{{ old('confirmation') }}">
                    <x-input-error :messages="$errors->get('confirmation')" />
                    <div><button class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700">Remettre à zéro les espaces cochés</button></div>
                </section>
            </form>
        @endif

        <p class="text-xs text-slate-500">En ligne de commande : <code>php artisan platform:reset-test-data --workspace=3 --payments --credit --plan</code> (sans <code>--force</code>, la commande montre seulement ce qu'elle effacerait).</p>
    </div>
</x-app-layout>
