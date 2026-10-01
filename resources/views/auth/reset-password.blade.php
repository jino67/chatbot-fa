<x-guest-layout title="Nouveau mot de passe | {{ $brand['name'] }}">
    <h1 class="font-display text-3xl font-bold text-brand-950">Choisissez un nouveau mot de passe</h1>
    <p class="mt-2 text-slate-600">Au moins 8 caractères. Un mot de passe long, que vous n'utilisez nulle part ailleurs, vous protège mieux.</p>

    <form method="POST" action="{{ route('password.store') }}" class="mt-8 space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <x-input-label for="email" value="Adresse e-mail" />
            <x-text-input id="email" class="mt-1 block w-full {{ $request->email ? 'bg-slate-50 text-slate-600' : '' }}" type="email" name="email" :value="old('email', $request->email)" :readonly="(bool) $request->email" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <x-password-input id="password" name="password" label="Nouveau mot de passe" :meter="true" :required="true" :messages="$errors->get('password')" />
        <x-password-input id="password_confirmation" name="password_confirmation" label="Confirmer le mot de passe" :required="true" :messages="$errors->get('password_confirmation')" />

        <button class="btn-primary w-full py-3 text-base">Enregistrer le mot de passe</button>
    </form>

    <p class="mt-8 text-sm text-slate-600">
        <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:text-brand-800">Retour à la connexion</a>
    </p>
</x-guest-layout>
