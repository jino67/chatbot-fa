{{--
    Devise du compte : un menu clair, enregistré sur l'espace du client (pas dans le navigateur). Le choix recharge la page
    pour que tous les prix suivent, sans demi-mesure. Les paiements passés gardent la devise dans laquelle ils ont été faits.
--}}
@php $current = \App\Support\Currency::current(); @endphp
<form method="POST" action="{{ route('currency.update') }}" {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-3 gap-y-1']) }}>
    @csrf @method('PUT')
    <label for="account-currency" class="text-sm font-medium text-slate-700">Devise de votre compte</label>
    <select id="account-currency" name="currency" class="field !mt-0 w-auto py-1.5 pe-9 text-sm"
            onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
        @foreach (\App\Support\Currency::ALL as $code => $currency)
            <option value="{{ $code }}" @selected($code === $current)>{{ $currency['name'] }} ({{ $currency['symbol'] }})</option>
        @endforeach
    </select>
    <noscript><button class="btn-outline px-3 py-1.5 text-sm">Valider</button></noscript>
    <p class="w-full text-xs text-slate-500">Elle règle l'affichage des prix et de votre facturation. Vos paiements passés gardent leur devise.</p>
</form>
