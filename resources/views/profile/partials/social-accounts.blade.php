@php
    $login = app(\App\Social\Auth\SocialLogin::class);
    $linked = $socialAccounts->keyBy('provider');
    $enabled = $login->enabled();
    $providers = collect(\App\Social\Auth\SocialLogin::PROVIDERS)->keys()->filter(fn ($key) => isset($enabled[$key]) || $linked->has($key));
@endphp
<section>
    <header class="flex items-start gap-4">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-50 transition-all duration-300 group-hover:rounded-2xl group-hover:rounded-bl-sm"><x-illus name="link" class="h-9 w-9" /></span>
        <div>
            <h2 class="font-display text-lg font-bold text-brand-950">Connexions</h2>
            <p class="mt-1 text-sm text-slate-600">Reliez un compte Google, Apple, Microsoft ou Facebook pour vous connecter d'un seul geste, sans mot de passe à retenir.</p>
        </div>
    </header>

    @if (session('status') === 'social-linked') <p class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">Compte relié. Vous pouvez maintenant vous connecter avec lui.</p> @endif
    @if (session('status') === 'social-unlinked') <p class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">Liaison retirée.</p> @endif
    @if (session('error')) <p class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ session('error') }}</p> @endif

    <ul class="mt-6 divide-y divide-slate-100 rounded-2xl ring-1 ring-slate-200">
        @foreach ($providers as $key)
            @php $account = $linked->get($key); $label = ucfirst($key); @endphp
            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <p class="font-medium text-slate-900">{{ $label }}</p>
                    <p class="truncate text-sm text-slate-500">
                        @if ($account) Relié{{ $account->email ? ' : '.$account->email : '' }}@if ($account->last_login_at), dernière connexion {{ $account->last_login_at->diffForHumans() }}@endif
                        @else Non relié @endif
                    </p>
                </div>
                @if ($account)
                    <form method="POST" action="{{ route('social.unlink', $key) }}" onsubmit="return confirm('Retirer {{ $label }} de ce compte ?')">
                        @csrf @method('DELETE')
                        <button class="btn-outline px-4 py-2 text-sm">Retirer</button>
                    </form>
                @else
                    <a href="{{ route('social.link', $key) }}" class="btn-outline px-4 py-2 text-sm">Relier</a>
                @endif
            </li>
        @endforeach
    </ul>

    @unless ($user->has_password)
        <p class="mt-4 text-sm text-slate-600">Vous n'avez pas encore de mot de passe : c'est normal, vous vous connectez avec {{ $linked->keys()->map(fn ($k) => ucfirst($k))->implode(' ou ') ?: 'un compte externe' }}. Vous pouvez en choisir un dans la section « Mot de passe » ci-dessus.</p>
    @endunless
</section>
