<x-guest-layout title="Créer mon assistant WhatsApp et site web | {{ $brand['name'] }}" description="Créez gratuitement l'assistant de votre entreprise : il répond à vos clients sur WhatsApp et sur votre site web à partir de vos documents. Essai sans carte bancaire." :indexable="true">
    <h1 class="font-display text-3xl font-bold text-brand-950">Créez votre assistant</h1>
    <p class="mt-2 text-slate-600">Gratuit pour démarrer, sans carte bancaire. Dix minutes suffisent.</p>

    <x-social-buttons divider="ou avec votre e-mail" />

    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="name" value="Votre nom" />
            <x-text-input id="name" class="mt-1 block w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="company" value="Nom de votre entreprise" />
            <x-text-input id="company" class="mt-1 block w-full" type="text" name="company" :value="old('company')" required autocomplete="organization" />
            <x-input-error :messages="$errors->get('company')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="email" value="Adresse e-mail" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-input-label for="password" value="Mot de passe" />
                <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="password_confirmation" value="Confirmation" />
                <x-text-input id="password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>
        </div>

        <button class="btn-accent w-full py-3 text-base">Créer mon espace gratuit</button>

        <p class="text-xs text-slate-600">
            En créant un compte, vous acceptez nos <a class="underline" href="{{ route('legal.terms') }}" target="_blank" rel="noopener">conditions d'utilisation</a>
            et notre <a class="underline" href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">politique de confidentialité</a>.
        </p>
    </form>

    <p class="mt-8 text-sm text-slate-600">
        Déjà inscrit ? <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:text-brand-800">Se connecter</a>
    </p>
</x-guest-layout>
