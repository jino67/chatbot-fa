{{--
    Champ de mot de passe : bouton pour l'afficher ou le masquer (utile au téléphone, où l'on se trompe en tapant) et,
    sur demande, une jauge de robustesse. La jauge est un conseil : c'est le serveur qui décide de ce qui est accepté.
--}}
@props(['id', 'name', 'label', 'autocomplete' => 'new-password', 'meter' => false, 'messages' => null, 'hint' => null, 'required' => false, 'placeholder' => null])
<div x-data="{
        show: false,
        value: '',
        get level() {
            const v = this.value;
            if (!v) return 0;
            if (v.length < 8) return 1;
            let s = 2;
            if (/[a-z]/.test(v) && /[A-Z]/.test(v) && /\d/.test(v)) s++;
            if (/[^A-Za-z0-9]/.test(v) || v.length >= 14) s++;
            return s;
        },
        get label() { return ['', 'Trop court : 8 caractères au moins', 'Correct', 'Bon', 'Très bon'][this.level] || ''; },
    }" {{ $attributes->only('class') }}>
    <x-input-label :for="$id" :value="$label" />
    <div class="relative">
        <input :type="show ? 'text' : 'password'" id="{{ $id }}" name="{{ $name }}" x-model="value" autocomplete="{{ $autocomplete }}"
               @if ($placeholder) placeholder="{{ $placeholder }}" @endif @required($required) class="field pe-12">
        <button type="button" @click="show = !show" :aria-pressed="show" :aria-label="show ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                class="absolute inset-y-0 end-0 mt-1 grid w-11 place-items-center rounded-e-xl text-slate-500 transition hover:text-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
            <svg x-show="!show" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg x-show="show" x-cloak viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 6.1A9.8 9.8 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4M6.5 6.9C3.7 8.7 2 12 2 12s3.5 7 10 7c1.6 0 3-.4 4.3-1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
        </button>
    </div>
    @if ($meter)
        <div class="mt-2" x-show="value.length" x-cloak>
            <div class="flex gap-1.5" aria-hidden="true">
                @foreach ([1, 2, 3, 4] as $step)
                    <span class="h-1.5 flex-1 rounded-full transition-colors duration-300"
                          :class="level >= {{ $step }} ? (level === 1 ? 'bg-red-500' : (level === 2 ? 'bg-accent-500' : 'bg-emerald-500')) : 'bg-slate-200'"></span>
                @endforeach
            </div>
            <p class="mt-1 text-xs text-slate-600" aria-live="polite" x-text="label"></p>
        </div>
    @endif
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    <x-input-error class="mt-2" :messages="$messages" />
</div>
