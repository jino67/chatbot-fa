<x-app-layout title="Développeurs | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Développeurs" subtitle="Vos clés d'API : appelez votre assistant depuis votre application.">
            <x-slot name="actions">
                <a href="{{ route('developers') }}" class="btn-outline">Documentation</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">

        {{-- La cle en clair, une seule fois --}}
        @if ($plain = session('new_api_key'))
            <div x-data="{ copied: false }" class="animate-toast-in rounded-2xl rounded-bl-md border border-accent-300 bg-accent-50 p-5">
                <p class="font-semibold text-brand-950">Votre nouvelle clé</p>
                <p class="mt-1 text-sm text-slate-700">Copiez-la maintenant et gardez-la secrète : elle ne sera plus jamais affichée.</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <code class="min-w-0 flex-1 break-all rounded-xl bg-white px-3 py-2.5 font-mono text-sm tracking-wide">{{ $plain }}</code>
                    <button type="button" class="btn-primary" @click="navigator.clipboard.writeText('{{ $plain }}'); copied = true; setTimeout(() => copied = false, 2000)" x-text="copied ? 'Copiée' : 'Copier'"></button>
                </div>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <section class="surface">
                <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-display text-lg font-bold">Vos clés</h2></div>
                @if ($keys->isEmpty())
                    <div class="px-5 py-10 text-center text-sm text-slate-600">
                        <x-illus name="code" class="il-live mx-auto h-16 w-16" />
                        <p class="mt-2">Aucune clé pour le moment. Créez-en une pour appeler votre assistant.</p>
                    </div>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($keys as $key)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                <div class="min-w-0">
                                    <p class="font-semibold text-brand-950">{{ $key->name }} @if ($key->isRevoked()) <x-badge tone="red">Révoquée</x-badge> @else <x-badge tone="green">Active</x-badge> @endif</p>
                                    <p class="mt-0.5 text-xs text-slate-500"><span class="font-mono">{{ $key->prefix }}••••••••</span> · assistant « {{ $key->bot?->name }} » · {{ $key->last_used_at ? 'utilisée '.$key->last_used_at->diffForHumans() : 'jamais utilisée' }}</p>
                                </div>
                                @unless ($key->isRevoked())
                                    <form method="POST" action="{{ route('api-keys.destroy', $key) }}" onsubmit="return confirm('Révoquer cette clé ? Les applications qui l\'utilisent cesseront de fonctionner.')">
                                        @csrf @method('DELETE')
                                        <button class="text-sm font-medium text-red-600 hover:text-red-800">Révoquer</button>
                                    </form>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <div class="space-y-6">
                <section class="surface p-5">
                    <h2 class="font-display text-lg font-bold">Nouvelle clé</h2>
                    <form method="POST" action="{{ route('api-keys.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <div><x-input-label for="key-name" value="Nom (pour vous y retrouver)" /><input id="key-name" name="name" required maxlength="80" class="field" placeholder="Application mobile" value="{{ old('name') }}"></div>
                        <div>
                            <x-input-label for="key-bot" value="Assistant" />
                            <select id="key-bot" name="bot_id" class="field">@foreach ($bots as $bot) <option value="{{ $bot->id }}">{{ $bot->name }}</option> @endforeach</select>
                        </div>
                        <button class="btn-primary w-full">Créer la clé</button>
                    </form>
                </section>

                <section class="surface p-5">
                    <h2 class="font-display text-lg font-bold">Consommation du mois</h2>
                    @php $pct = min(100, (int) round(100 * $usage['used'] / max(1, $usage['limit']))); @endphp
                    <p class="mt-3 font-display text-2xl font-bold">{{ number_format($usage['used'], 0, ',', "\u{202F}") }} <span class="text-sm font-normal text-slate-500">/ {{ number_format($usage['limit'], 0, ',', "\u{202F}") }} réponses</span></p>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="bar-fill h-full rounded-full {{ $pct >= 90 ? 'bg-red-500' : 'bg-brand-500' }}" style="width: {{ $pct }}%"></div></div>
                    <p class="mt-2 text-xs text-slate-500">Offre {{ $plan?->name }}. Chaque appel à <code>/api/v1/chat</code> compte pour une réponse.</p>
                </section>
            </div>
        </div>

        <section class="surface p-5">
            <h2 class="font-display text-lg font-bold">Essayer tout de suite</h2>
            <pre class="mt-3 overflow-x-auto rounded-xl bg-brand-950 p-4 text-[12.5px] leading-relaxed text-sky-100"><code>curl {{ url('/api/v1/chat') }} \
  -H "Authorization: Bearer kma_votre_cle" \
  -H "Content-Type: application/json" \
  -d '{"message": "Vous livrez à Bobo ?"}'</code></pre>
        </section>
    </div>
</x-app-layout>
