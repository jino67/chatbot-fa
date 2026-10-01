<section>
    <header class="flex items-start gap-4">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-50 transition-all duration-300 group-hover:rounded-2xl group-hover:rounded-bl-sm"><x-illus name="voice" class="h-9 w-9" /></span>
        <div>
            <h2 class="font-display text-lg font-bold text-brand-950">Mes informations</h2>
            <p class="mt-1 text-sm text-slate-600">Le nom affiché dans l'application et l'adresse e-mail qui sert à vous connecter et à vous prévenir.</p>
        </div>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-5">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" value="Nom" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" value="Adresse e-mail" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="mt-3 rounded-xl border border-accent-300 bg-accent-50 px-4 py-3 text-sm text-brand-950">
                    Votre adresse e-mail n'est pas encore vérifiée.
                    <button form="send-verification" class="font-semibold text-brand-700 underline decoration-brand-300 underline-offset-2 hover:text-brand-900">Renvoyer le lien de vérification</button>
                </div>
            @endif
        </div>

        <div>
            <x-input-label for="phone" value="Mon numéro WhatsApp (facultatif)" />
            <x-text-input id="phone" name="phone" type="tel" class="mt-1 block w-full" :value="old('phone', $user->phone)" placeholder="+226 70 00 00 00" autocomplete="tel" />
            <x-input-error class="mt-2" :messages="$errors->get('phone')" />
            <p class="mt-1 text-xs text-slate-500">Il peut recevoir les alertes des commandes et des demandes de personne : le choix se fait dans « Alertes ».</p>
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>Enregistrer</x-primary-button>
        </div>
    </form>
</section>
