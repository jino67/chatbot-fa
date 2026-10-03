<section>
    <header class="flex items-start gap-4">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-50 transition-all duration-300 group-hover:rounded-2xl group-hover:rounded-bl-sm"><x-illus name="globe" class="h-9 w-9" /></span>
        <div>
            <h2 class="font-display text-lg font-bold text-brand-950">Appareils connectés</h2>
            <p class="mt-1 text-sm text-slate-600">Les téléphones et navigateurs où votre compte est ouvert. Vous ne reconnaissez pas un appareil ? Déconnectez-le, puis changez votre mot de passe.</p>
        </div>
    </header>

    <ul class="mt-5 divide-y divide-slate-100 rounded-2xl rounded-bl-md border border-slate-200">
        @foreach ($sessions as $session)
            <li class="flex items-center gap-4 px-4 py-3.5">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700" aria-hidden="true">
                    @if ($session['mobile'])
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/></svg>
                    @else
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="12" rx="2"/><path d="M8 20h8M12 16.5V20"/></svg>
                    @endif
                </span>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-800">
                        {{ $session['label'] }}
                        @if ($session['current']) <x-badge tone="green">Cet appareil</x-badge> @endif
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        @if ($session['ip']) {{ $session['ip'] }}, @endif
                        {{ $session['current'] ? 'actif maintenant' : 'actif '.$session['last']->diffForHumans() }}
                    </p>
                </div>
            </li>
        @endforeach
    </ul>

    @if ($sessions->count() > 1)
        <form method="post" action="{{ route('profile.logout-others') }}" class="mt-5 flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-[14rem] flex-1">
                <x-input-label for="logout_others_password" value="{{ $user->has_password ? 'Votre mot de passe, pour confirmer' : 'Votre adresse e-mail, pour confirmer' }}" />
                <x-text-input id="logout_others_password" name="password" :type="$user->has_password ? 'password' : 'email'" class="mt-1 block w-full" :autocomplete="$user->has_password ? 'current-password' : 'email'" required />
                <x-input-error :messages="$errors->logoutOthers->get('password')" class="mt-2" />
            </div>
            <x-secondary-button type="submit">Déconnecter les autres appareils</x-secondary-button>
        </form>
    @endif
</section>
