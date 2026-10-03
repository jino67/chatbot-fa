<x-guest-layout title="Votre adresse e-mail | {{ $brand['name'] }}">
    <h1 class="font-display text-3xl font-bold text-brand-950">Une dernière information</h1>
    <p class="mt-3 text-slate-600">
        {{ ucfirst($identity->provider) }} ne nous a pas transmis votre adresse e-mail. Indiquez-la : c'est là que nous vous écrirons au sujet de votre compte.
    </p>

    <form method="POST" action="{{ route('social.email.store') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" value="Adresse e-mail" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="email" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <button class="btn-primary w-full py-3 text-base">Continuer</button>
    </form>
</x-guest-layout>
