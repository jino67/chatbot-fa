{{--
    Choix des langues de l'assistant : on coche celles que parle l'assistant, on désigne la principale (celle des
    messages de repli et de la réponse quand la langue du client n'est pas reconnue). Champs envoyés : languages[] et language.
    La fiabilité de chaque langue est dite franchement ; le micro indique si les messages vocaux y sont compris.
--}}
@props(['selected' => ['fr'], 'primary' => 'fr'])
@php
    $catalog = \App\Support\Languages::all();
    $tiers = \App\Support\Languages::tiers();
    $localListening = app(\App\Speech\SpeechFactory::class)->local() !== null;
    $selected = \App\Support\Languages::normalize((array) $selected, $primary);
@endphp
<div x-data="{ chosen: @js($selected), primary: @js($selected[0]) }" class="space-y-3">
    <div class="grid gap-2 sm:grid-cols-2">
        @foreach ($catalog as $code => $lang)
            @php
                $tier = $tiers[$lang['tier']];
                $listens = $lang['stt'] === 'openai' || ($lang['stt'] === 'local' && $localListening);
            @endphp
            <label class="group relative flex cursor-pointer items-start gap-3 rounded-2xl border bg-white p-3 transition hover:border-brand-300 hover:shadow-sm"
                   :class="chosen.includes('{{ $code }}') ? 'border-brand-500 ring-2 ring-brand-500/15' : 'border-slate-200'">
                <input type="checkbox" name="languages[]" value="{{ $code }}" x-model="chosen"
                       @change="if (! chosen.length) { chosen = ['fr']; } if (! chosen.includes(primary)) { primary = chosen[0]; }"
                       class="mt-1 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-semibold text-brand-950">{{ $lang['name'] }}</span>
                        @if ($lang['native'] !== $lang['name'])
                            <span class="text-sm text-slate-500" @if ($lang['rtl']) dir="rtl" lang="{{ $code }}" @endif>{{ $lang['native'] }}</span>
                        @endif
                        <x-badge :tone="$tier['tone']">{{ $tier['label'] }}</x-badge>
                        @if ($listens)
                            <span class="inline-flex items-center gap-1 text-xs text-slate-500" title="Les messages vocaux dans cette langue sont compris">
                                <svg viewBox="0 0 20 20" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="7.5" y="2.5" width="5" height="9" rx="2.5"/><path d="M4.5 9.5a5.5 5.5 0 0 0 11 0M10 15v2.5"/></svg>
                                vocaux
                            </span>
                        @endif
                    </span>
                    <span x-show="chosen.includes('{{ $code }}')" x-cloak class="mt-1.5 flex items-center gap-2 text-xs text-slate-600">
                        <input type="radio" name="language" value="{{ $code }}" x-model="primary" :disabled="! chosen.includes('{{ $code }}')" class="border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span :class="primary === '{{ $code }}' ? 'font-semibold text-brand-700' : ''" x-text="primary === '{{ $code }}' ? 'Langue principale' : 'Définir comme principale'"></span>
                    </span>
                </span>
            </label>
        @endforeach
    </div>

    @foreach (['assisted', 'experimental'] as $level)
        @php $codes = array_keys(array_filter($catalog, fn ($l) => $l['tier'] === $level)); @endphp
        <p x-show="{{ json_encode($codes) }}.some(c => chosen.includes(c))" x-cloak class="rounded-xl bg-accent-50 px-3 py-2 text-sm text-accent-900">
            <strong>{{ $tiers[$level]['label'] }} :</strong> {{ $tiers[$level]['help'] }}
        </p>
    @endforeach
    <p x-show="chosen.length > 1" x-cloak class="text-xs text-slate-500">Avec plusieurs langues, l'assistant répond dans la langue du dernier message du client, et dans la langue principale quand il ne la reconnaît pas.</p>
</div>
