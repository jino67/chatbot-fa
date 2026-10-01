@php
    $workspace = $user->currentWorkspace();
    $plan = $workspace?->planModel();

    // Sommaire de la page : chaque entrée renvoie vers une section, et la section lue s'allume (voir x-init).
    $menu = array_filter([
        'infos' => ['Mes informations', 'users'],
        'securite' => ['Mot de passe', 'shield'],
        $workspace ? 'alertes' : null => ['Mes alertes', 'bolt'],
        'application' => ['Application', 'phone'],
        $sessions->isNotEmpty() ? 'appareils' : null => ['Appareils connectés', 'globe'],
        'suppression' => ['Supprimer mon compte', 'x'],
    ], fn ($entry, $key) => $key !== '', ARRAY_FILTER_USE_BOTH);
@endphp
<x-app-layout title="Mon profil | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Mon profil" subtitle="Vos informations, la sécurité de votre compte et la façon dont vous utilisez l'application." />
    </x-slot>

    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8"
         x-data="{ active: 'infos' }"
         x-init="if ('IntersectionObserver' in window) { const io = new IntersectionObserver((entries) => entries.forEach((e) => { if (e.isIntersecting) active = e.target.id; }), { rootMargin: '-25% 0px -60% 0px' }); $el.querySelectorAll('[data-spy]').forEach((s) => io.observe(s)); }">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]">

            {{-- Colonne de gauche : qui je suis, puis le sommaire de la page --}}
            <aside class="stagger space-y-4 lg:sticky lg:top-6 lg:self-start">
                <div class="surface group relative overflow-hidden p-6 text-center">
                    <div class="wax-navy absolute inset-x-0 top-0 h-24" aria-hidden="true"></div>
                    <div class="relative mouth mx-auto mt-6 grid h-20 w-20 place-items-center rounded-full rounded-bl-lg bg-accent-500 font-display text-3xl font-bold text-brand-950 ring-4 ring-white transition-all duration-500 group-hover:rounded-bl-full group-hover:rotate-6">
                        {{ strtoupper(mb_substr($user->name, 0, 1)) }}
                    </div>
                    <p class="mt-4 font-display text-lg font-bold leading-tight">{{ $user->name }}</p>
                    <p class="mt-1 break-all text-sm text-slate-600">{{ $user->email }}</p>
                    <div class="mt-3 flex flex-wrap justify-center gap-1.5">
                        <x-badge tone="brand">{{ $user->roleLabel() }}</x-badge>
                        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && $user->hasVerifiedEmail()) <x-badge tone="green">E-mail vérifié</x-badge> @endif
                        @if ($user->pwa_installed_at) <x-badge tone="green">Application installée</x-badge> @endif
                    </div>

                    <dl class="mt-5 space-y-2.5 border-t border-slate-100 pt-4 text-left text-sm">
                        @if ($workspace)
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Espace</dt><dd class="truncate font-medium">{{ $workspace->name }}</dd></div>
                            @if ($plan)
                                <div class="flex justify-between gap-3"><dt class="text-slate-500">Offre</dt><dd class="font-medium">{{ $plan->name }}</dd></div>
                            @endif
                        @endif
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Membre depuis</dt><dd class="font-medium">{{ $user->created_at?->translatedFormat('F Y') }}</dd></div>
                        @if ($user->last_login_at)
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Dernière connexion</dt><dd class="font-medium">{{ $user->last_login_at->diffForHumans() }}</dd></div>
                        @endif
                    </dl>
                </div>

                <nav class="surface hidden p-2 lg:block" aria-label="Sections du profil">
                    @foreach ($menu as $id => [$label, $icon])
                        <a href="#{{ $id }}" :class="active === '{{ $id }}' ? 'bg-brand-50 text-brand-800' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-800'"
                           class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition">
                            <x-icon name="{{ $icon }}" class="h-4 w-4 shrink-0" /> {{ $label }}
                        </a>
                    @endforeach
                </nav>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn-outline w-full"><x-icon name="logout" class="h-4 w-4" /> Se déconnecter</button>
                </form>
            </aside>

            <div class="stagger space-y-6">
                <section id="infos" data-spy class="surface-link group scroll-mt-24 p-6 sm:p-8">
                    @include('profile.partials.update-profile-information-form')
                </section>

                <section id="securite" data-spy class="surface-link group scroll-mt-24 p-6 sm:p-8">
                    @include('profile.partials.update-password-form')
                </section>

                @if ($workspace)
                    <section id="alertes" data-spy class="surface-link group scroll-mt-24 p-6 sm:p-8">
                        @include('profile.partials.alerts-shortcut')
                    </section>
                @endif

                <section id="application" data-spy class="surface group scroll-mt-24 p-6 sm:p-8">
                    @include('profile.partials.app-install')
                </section>

                @if ($sessions->isNotEmpty())
                    <section id="appareils" data-spy class="surface group scroll-mt-24 p-6 sm:p-8">
                        @include('profile.partials.sessions')
                    </section>
                @endif

                <section id="suppression" data-spy class="surface group scroll-mt-24 border-red-200/70 p-6 sm:p-8">
                    @include('profile.partials.delete-user-form')
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
