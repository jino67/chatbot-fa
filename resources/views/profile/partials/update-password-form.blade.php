<section>
    <header class="flex items-start gap-4">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-50 transition-all duration-300 group-hover:rounded-2xl group-hover:rounded-bl-sm"><x-illus name="shield" class="h-9 w-9" /></span>
        <div>
            <h2 class="font-display text-lg font-bold text-brand-950">Mot de passe</h2>
            <p class="mt-1 text-sm text-slate-600">Choisissez un mot de passe long et unique : au moins 8 caractères, en mélangeant lettres et chiffres.</p>
        </div>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-5">
        @csrf
        @method('put')

        <x-password-input id="update_password_current_password" name="current_password" label="Mot de passe actuel" autocomplete="current-password"
                          :messages="$errors->updatePassword->get('current_password')" />

        <div class="grid gap-5 sm:grid-cols-2">
            <x-password-input id="update_password_password" name="password" label="Nouveau mot de passe" :meter="true"
                              :messages="$errors->updatePassword->get('password')" />
            <x-password-input id="update_password_password_confirmation" name="password_confirmation" label="Confirmer le mot de passe"
                              :messages="$errors->updatePassword->get('password_confirmation')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>Changer le mot de passe</x-primary-button>
        </div>
    </form>

    {{-- Mot de passe actuel oublié : un lien part vers l'adresse du compte, sans qu'il faille l'ancien mot de passe. --}}
    <div class="mt-8 rounded-2xl rounded-bl-md bg-slate-50 p-5">
        <p class="text-sm font-semibold text-brand-950">Vous ne vous souvenez plus de votre mot de passe actuel ?</p>
        <p class="mt-1 text-sm text-slate-600">Nous envoyons un lien à <strong class="font-semibold text-slate-800">{{ $user->email }}</strong> pour en choisir un nouveau, sans avoir besoin de l'ancien.</p>
        <form method="post" action="{{ route('profile.password-link') }}" class="mt-3">
            @csrf
            <button class="btn-outline px-4 py-2">M'envoyer le lien par e-mail</button>
        </form>
    </div>
</section>
