<section class="space-y-5">
    <header class="flex items-start gap-4">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-red-50 text-red-600 transition-all duration-300 group-hover:rounded-2xl group-hover:rounded-bl-sm">
            <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>
        </span>
        <div>
            <h2 class="font-display text-lg font-bold text-brand-950">Supprimer mon compte</h2>
            <p class="mt-1 text-sm text-slate-600">La suppression est définitive : votre compte et les données qui lui sont liées sont effacés. Téléchargez ce que vous voulez garder avant de continuer.</p>
        </div>
    </header>

    <x-danger-button x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">Supprimer mon compte</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="font-display text-lg font-bold text-brand-950">Supprimer définitivement votre compte ?</h2>

            <p class="mt-2 text-sm text-slate-600">Cette action ne peut pas être annulée. {{ $user->has_password ? 'Saisissez votre mot de passe pour confirmer.' : 'Saisissez l\'adresse e-mail de votre compte pour confirmer.' }}</p>

            <div class="mt-6">
                <x-input-label for="password" value="{{ $user->has_password ? 'Mot de passe' : 'Adresse e-mail' }}" class="sr-only" />
                <x-text-input id="password" name="password" :type="$user->has_password ? 'password' : 'email'" class="mt-1 block w-full sm:w-3/4" :placeholder="$user->has_password ? 'Votre mot de passe' : $user->email" />
                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close')">Annuler</x-secondary-button>
                <x-danger-button>Supprimer mon compte</x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
