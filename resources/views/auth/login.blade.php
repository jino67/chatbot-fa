<x-guest-layout title="Connexion | {{ $brand['name'] }}">
    <h1 class="font-display text-3xl font-bold text-brand-950">Bon retour</h1>
    <p class="mt-2 text-slate-600">Connectez-vous pour retrouver vos assistants et vos conversations.</p>

    <x-auth-session-status class="mt-4" :status="session('status')" />

    @if ($errors->has('social'))
        <p class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $errors->first('social') }}</p>
    @endif

    <x-social-buttons />

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" value="Adresse e-mail" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <div class="flex items-center justify-between">
                <x-input-label for="password" value="Mot de passe" />
                @if (Route::has('password.request'))
                    <a class="text-sm text-brand-600 hover:text-brand-800" href="{{ route('password.request') }}">Mot de passe oublié ?</a>
                @endif
            </div>
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="flex items-center gap-2 text-sm text-slate-700">
            <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" name="remember">
            Rester connecté sur cet appareil
        </label>

        <button class="btn-primary w-full py-3 text-base">Se connecter</button>
    </form>

    <p class="mt-8 text-sm text-slate-600">
        Pas encore de compte ? <a href="{{ route('register') }}" class="font-semibold text-brand-600 hover:text-brand-800">Créer mon assistant gratuitement</a>
    </p>
</x-guest-layout>
