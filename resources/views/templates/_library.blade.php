{{--
    Bibliothèque de modèles WhatsApp : des paquets par métier, et des cartes avec l'aperçu du message tel que le client
    le verra (bulle, boutons). Ajouter un modèle l'envoie à WhatsApp pour approbation (voir TemplateProvisioner).
--}}
@php
    $groupsPresent = collect($library['items'])->pluck('group')->unique()->all();
    $bold = fn (string $text) => preg_replace('/\*(.+?)\*/u', '<strong>$1</strong>', e($text));
@endphp

<section class="surface mb-6 p-6" x-data="{ group: 'all', picked: [] }">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h3 class="font-display text-lg font-bold">Bibliothèque de modèles</h3>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                {{ count($library['items']) }} messages déjà rédigés, prêts à partir : suivi de commande, rappel de rendez-vous, devis, promotions. Ajoutez-les en un clic, WhatsApp les approuve en général en quelques minutes ou quelques heures. Le nom de votre entreprise est déjà inscrit dedans.
            </p>
        </div>
        <div class="inline-flex rounded-full bg-slate-100 p-1 text-sm font-medium" role="group" aria-label="Langue des modèles">
            @foreach ($library['languages'] as $code)
                <a href="{{ route('templates.index', [$bot, 'langue' => $code]) }}"
                   class="rounded-full px-4 py-1.5 transition {{ $library['language'] === $code ? 'bg-white text-brand-900 shadow-sm' : 'text-slate-600 hover:text-brand-900' }}">{{ $code === 'fr' ? 'Français' : 'English' }}</a>
            @endforeach
        </div>
    </div>

    {{-- Paquets par métier --}}
    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($library['packs'] as $key => $pack)
            <form method="POST" action="{{ route('templates.pack', $bot) }}"
                  class="flex flex-col rounded-2xl border p-4 {{ $key === $library['suggestedPack'] ? 'border-brand-300 bg-brand-50/60' : 'border-slate-200 bg-white' }}">
                @csrf
                <input type="hidden" name="pack" value="{{ $key }}">
                <input type="hidden" name="language" value="{{ $library['language'] }}">
                <div class="flex flex-wrap items-center gap-2">
                    <h4 class="font-semibold text-brand-950">{{ $pack['label'] }}</h4>
                    @if ($key === $library['suggestedPack']) <x-badge tone="green">Conseillé pour vous</x-badge> @endif
                </div>
                <p class="mt-1 flex-1 text-sm text-slate-600">{{ $pack['description'] }}</p>
                <div class="mt-3 flex items-center justify-between gap-3">
                    <span class="text-xs text-slate-500">{{ $library['packCounts'][$key] ?? 0 }} modèles</span>
                    <button class="btn-outline px-4 py-1.5 text-sm">Ajouter le paquet</button>
                </div>
            </form>
        @endforeach
    </div>

    {{-- Filtre par thème --}}
    <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Thèmes">
            <button type="button" @click="group = 'all'" :class="group === 'all' ? 'bg-brand-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-full px-3.5 py-1.5 text-sm font-medium transition">Tous</button>
            @foreach ($library['groups'] as $key => $label)
                @continue(! in_array($key, $groupsPresent, true))
                <button type="button" @click="group = '{{ $key }}'" :class="group === '{{ $key }}' ? 'bg-brand-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-full px-3.5 py-1.5 text-sm font-medium transition">{{ $label }}</button>
            @endforeach
        </div>

        <form id="library-form" method="POST" action="{{ route('templates.add', $bot) }}" class="flex items-center gap-3">
            @csrf
            <input type="hidden" name="language" value="{{ $library['language'] }}">
            <span class="text-sm text-slate-600" x-text="picked.length ? picked.length + ' coché(s)' : 'Cochez des modèles'"></span>
            <button class="btn-primary px-4 py-1.5 text-sm" :disabled="picked.length === 0" :class="picked.length === 0 && 'cursor-not-allowed opacity-50'">Ajouter la sélection</button>
        </form>
    </div>

    {{-- Les modèles --}}
    <div class="mt-4 grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
        @foreach ($library['items'] as $key => $item)
            @php $have = $library['existing'][$key] ?? null; @endphp
            <article x-show="group === 'all' || group === '{{ $item['group'] }}'" class="flex flex-col rounded-2xl border border-slate-200 bg-white p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h4 class="font-semibold text-brand-950">{{ $item['title'] }}</h4>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            <x-badge :tone="$item['category'] === 'MARKETING' ? 'amber' : 'brand'">{{ $item['category'] === 'MARKETING' ? 'Marketing' : 'Utilitaire' }}</x-badge>
                            @if ($have) <x-badge :tone="$have->statusTone()">{{ $have->statusLabel() }}</x-badge> @endif
                        </div>
                    </div>
                    @unless ($have)
                        <input type="checkbox" form="library-form" name="keys[]" value="{{ $key }}" x-model="picked"
                               class="mt-1 h-5 w-5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" aria-label="Choisir « {{ $item['title'] }} »">
                    @endunless
                </div>

                {{-- Aperçu : la bulle telle qu'elle apparaît dans WhatsApp --}}
                <div class="mt-3 flex-1 rounded-xl bg-[#efeae2] p-3">
                    <div class="max-w-[96%] rounded-lg rounded-tl-sm bg-white px-3 py-2 text-[13px] leading-snug text-slate-800 shadow-sm">
                        <p class="whitespace-pre-line">{!! $bold($item['preview']) !!}</p>
                        @if ($item['footer']) <p class="mt-1.5 text-[11px] text-slate-500">{{ $item['footer'] }}</p> @endif
                    </div>
                    @foreach ($item['buttons'] as $button)
                        <div class="mt-1 max-w-[96%] rounded-lg bg-white px-3 py-1.5 text-center text-[13px] font-medium text-sky-700 shadow-sm">
                            @if ($button['type'] === 'PHONE_NUMBER') ☎ @elseif ($button['type'] === 'URL') ↗ @endif {{ $button['text'] }}
                        </div>
                    @endforeach
                </div>

                @if ($item['vars'])
                    <p class="mt-2 text-xs text-slate-500">À renseigner à l'envoi : {{ implode(', ', $item['vars']) }}.</p>
                @endif
            </article>
        @endforeach
    </div>
</section>
