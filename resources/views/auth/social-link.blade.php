<x-guest-layout title="Relier mon compte | {{ $brand['name'] }}">
    <h1 class="font-display text-3xl font-bold text-brand-950">Ce compte existe déjà</h1>
    <p class="mt-3 text-slate-600">
        Un compte {{ $brand['name'] }} utilise déjà l'adresse <strong class="font-semibold text-slate-800">{{ $email }}</strong>.
        Saisissez son mot de passe pour le relier à {{ ucfirst($identity->provider) }}. Ensuite, un seul bouton suffira pour vous connecter.
    </p>

    <form method="POST" action="{{ route('social.confirm.store') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="password" value="Mot de passe de votre compte {{ $brand['name'] }}" />
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autofocus autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <button class="btn-primary w-full py-3 text-base">Relier et me connecter</button>
    </form>

    <p class="mt-6 text-sm text-slate-600">
        <a class="font-medium text-brand-600 hover:text-brand-800" href="{{ route('password.request') }}">Mot de passe oublié ?</a>
        <span class="mx-2 text-slate-300" aria-hidden="true">|</span>
        <a class="font-medium text-brand-600 hover:text-brand-800" href="{{ route('login') }}">Utiliser une autre méthode</a>
    </p>
</x-guest-layout>
