<x-guest-layout title="Confirmer votre mot de passe | {{ $brand['name'] }}">
    <h1 class="font-display text-3xl font-bold text-brand-950">Une confirmation, par sécurité</h1>
    <p class="mt-2 text-slate-600">Cette zone est protégée. Saisissez votre mot de passe pour continuer.</p>

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-8 space-y-5">
        @csrf

        <x-password-input id="password" name="password" label="Mot de passe" autocomplete="current-password" :required="true" :messages="$errors->get('password')" />

        <button class="btn-primary w-full py-3 text-base">Confirmer</button>
    </form>

    <p class="mt-8 text-sm text-slate-600">
        <a href="{{ route('password.request') }}" class="font-semibold text-brand-600 hover:text-brand-800">Mot de passe oublié ?</a>
    </p>
</x-guest-layout>
