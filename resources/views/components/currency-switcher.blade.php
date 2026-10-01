{{--
    Choix de la devise d'affichage. Le script (motion.js) change les prix sans recharger la page ; sans JavaScript,
    chaque lien recharge la page avec ?devise=XXX (voir SetCurrency).
--}}
@props(['tone' => 'light'])
@php $current = \App\Support\Currency::current(); @endphp
<div data-currency-switcher {{ $attributes->merge(['class' => 'inline-flex rounded-full p-1 text-sm font-medium '.($tone === 'dark' ? 'bg-white/10' : 'bg-slate-100')]) }} role="group" aria-label="Devise d'affichage">
    @foreach (\App\Support\Currency::ALL as $code => $currency)
        <a href="{{ request()->fullUrlWithQuery(['devise' => $code]) }}#tarifs"
           data-currency="{{ $code }}" data-on="{{ $code === $current ? 'true' : 'false' }}"
           @class([
               'rounded-full px-3.5 py-1.5 transition duration-200',
               'text-white/80 hover:text-white data-[on=true]:bg-white data-[on=true]:text-brand-900 data-[on=true]:shadow-sm' => $tone === 'dark',
               'text-slate-600 hover:text-brand-900 data-[on=true]:bg-white data-[on=true]:text-brand-900 data-[on=true]:shadow-sm' => $tone !== 'dark',
           ])
           @if ($code === $current) aria-current="true" @endif
           title="{{ $currency['name'] }}">{{ $currency['short'] }}</a>
    @endforeach
</div>
