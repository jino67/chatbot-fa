<x-guest-layout title="Terminer mon profil | {{ $brand['name'] }}">
    <h1 class="font-display text-3xl font-bold text-brand-950">Bienvenue, {{ \Illuminate\Support\Str::of($user->name)->before(' ') }} !</h1>
    <p class="mt-3 text-slate-600">Encore une minute : dites-nous quelle est votre entreprise et sur quel numéro vos clients vous écrivent. Votre assistant en a besoin pour se présenter correctement.</p>

    <form method="POST" action="{{ route('account.complete.store') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="name" value="Votre nom" />
            <x-text-input id="name" class="mt-1 block w-full" type="text" name="name" :value="old('name', $user->name)" required autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="company" value="Nom de votre entreprise" />
            <x-text-input id="company" class="mt-1 block w-full" type="text" name="company" :value="old('company', $workspace?->name === $user->name ? '' : $workspace?->name)" required autofocus autocomplete="organization" placeholder="Boutique Awa" />
            <x-input-error :messages="$errors->get('company')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="phone" value="Votre numéro WhatsApp" />
            <x-text-input id="phone" class="mt-1 block w-full" type="tel" name="phone" :value="old('phone', $user->phone)" required autocomplete="tel" placeholder="+226 70 00 00 00" inputmode="tel" />
            <p class="mt-1 text-xs text-slate-500">Avec l'indicatif de votre pays. Notre équipe peut vous y aider pour la mise en route.</p>
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="country" value="Pays (facultatif)" />
            <x-text-input id="country" class="mt-1 block w-full" type="text" name="country" :value="old('country', $workspace?->country)" list="countries" autocomplete="country-name" placeholder="Burkina Faso" />
            <datalist id="countries">
                @foreach (['Burkina Faso', 'Côte d\'Ivoire', 'Sénégal', 'Mali', 'Niger', 'Togo', 'Bénin', 'Guinée', 'Cameroun', 'Gabon', 'Congo', 'RD Congo', 'Comores', 'Madagascar', 'Maroc', 'Tunisie', 'Algérie', 'France', 'Belgique', 'Canada'] as $country)
                    <option value="{{ $country }}"></option>
                @endforeach
            </datalist>
            <x-input-error :messages="$errors->get('country')" class="mt-2" />
        </div>

        @if ($relay)
            <div>
                <x-input-label for="contact_email" value="Votre adresse e-mail habituelle (facultatif)" />
                <x-text-input id="contact_email" class="mt-1 block w-full" type="email" name="contact_email" :value="old('contact_email')" autocomplete="email" />
                <p class="mt-1 text-xs text-slate-500">Apple a masqué votre adresse. Avec la vôtre, vous recevrez bien nos messages (factures, alertes, réponses à vos demandes).</p>
                <x-input-error :messages="$errors->get('contact_email')" class="mt-2" />
            </div>
        @endif

        <label class="flex items-start gap-2 text-sm text-slate-700">
            <input type="checkbox" name="terms" value="1" required @checked(old('terms')) class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <span>J'accepte les <a class="underline" href="{{ route('legal.terms') }}" target="_blank" rel="noopener">conditions d'utilisation</a> et la <a class="underline" href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">politique de confidentialité</a>.</span>
        </label>
        <x-input-error :messages="$errors->get('terms')" />

        <button class="btn-accent w-full py-3 text-base">Entrer dans mon espace</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button class="text-sm text-slate-500 underline hover:text-slate-700">Me déconnecter</button>
    </form>
</x-guest-layout>
