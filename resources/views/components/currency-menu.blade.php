{{--
    Choix de la devise, en menu deroulant compact (barre de navigation). Meme moteur que x-currency-switcher :
    le script change les prix de la page sans la recharger ; sans JavaScript, chaque lien recharge avec ?devise=XXX.
--}}
@props(['align' => 'right'])
@php $current = \App\Support\Currency::current(); @endphp
<div data-currency-switcher x-data="{ open: false }" @keydown.escape="open = false" @click.outside="open = false" {{ $attributes->merge(['class' => 'relative']) }}>
    <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="true" aria-label="Choisir la devise"
            class="flex items-center gap-1.5 rounded-full px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 hover:text-brand-800">
        <span data-currency-label>{{ \App\Support\Currency::ALL[$current]['short'] }}</span>
        <svg viewBox="0 0 20 20" class="h-3.5 w-3.5 transition-transform duration-300" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8l5 5 5-5"/></svg>
    </button>

    <div x-show="open" x-cloak
         x-transition:enter="transition duration-300 ease-[cubic-bezier(0.2,1.2,0.3,1)]" x-transition:enter-start="-translate-y-2 scale-95 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="scale-95 opacity-0"
         class="absolute {{ $align === 'left' ? 'left-0 origin-top-left' : 'right-0 origin-top-right' }} top-full z-50 mt-2 w-52 rounded-2xl rounded-tr-md bg-white p-1.5 shadow-pop ring-1 ring-slate-200">
        @foreach (\App\Support\Currency::ALL as $code => $currency)
            <a href="{{ request()->fullUrlWithQuery(['devise' => $code]) }}#tarifs" data-currency="{{ $code }}" data-label="{{ $currency['short'] }}" data-on="{{ $code === $current ? 'true' : 'false' }}"
               @click="open = false"
               class="flex items-center justify-between rounded-xl px-3 py-2 text-sm text-slate-700 transition hover:bg-brand-50 data-[on=true]:bg-brand-50 data-[on=true]:font-semibold data-[on=true]:text-brand-800">
                <span>{{ $currency['name'] }}</span>
                <span class="text-xs text-slate-500">{{ $currency['symbol'] }}</span>
            </a>
        @endforeach
    </div>
</div>
