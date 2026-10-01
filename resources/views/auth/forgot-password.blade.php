<x-guest-layout title="Mot de passe oublié | {{ $brand['name'] }}">
    <h1 class="font-display text-3xl font-bold text-brand-950">Mot de passe oublié ?</h1>
    <p class="mt-2 text-slate-600">
        Indiquez l'adresse e-mail de votre compte. Nous vous envoyons un lien pour choisir un nouveau mot de passe ; il reste valable {{ (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60) }} minutes.
    </p>

    @if (session('status'))
        <div class="mt-5 flex items-start gap-3 rounded-xl rounded-bl-sm border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> <span>{{ session('status') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" value="Adresse e-mail" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <button class="btn-primary w-full py-3 text-base">Recevoir le lien</button>
    </form>

    <p class="mt-8 text-sm text-slate-600">
        Vous vous en souvenez ? <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:text-brand-800">Se connecter</a>
    </p>
</x-guest-layout>
