<x-app-layout title="Équipe | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Équipe" subtitle="Comptes du personnel. Un admin gère les espaces clients ; un super admin gère aussi les offres, l'IA, les paramètres et l'équipe." />
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
        <section class="surface divide-y divide-slate-100">
            @foreach ($members as $member)
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 text-sm">
                    <div>
                        <p class="font-semibold text-brand-950">{{ $member->name }}
                            @if ($member->id === auth()->id()) <span class="font-normal text-slate-500">(vous)</span> @endif
                            @unless ($member->is_active) <x-badge tone="red">Désactivé</x-badge> @endunless
                        </p>
                        <p class="text-slate-600">{{ $member->email }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('admin.team.update', $member) }}" class="flex items-center gap-2">
                            @csrf @method('PUT')
                            <select name="role" class="field !mt-0 !w-auto" aria-label="Rôle de {{ $member->name }}" @disabled($member->id === auth()->id())>
                                <option value="admin" @selected($member->role === 'admin')>Admin</option>
                                <option value="super_admin" @selected($member->role === 'super_admin')>Super admin</option>
                            </select>
                            @if ($member->id !== auth()->id()) <button class="btn-outline !px-3 !py-1.5 text-xs">Changer</button> @endif
                        </form>
                        @if ($member->id !== auth()->id())
                            <form method="POST" action="{{ route('admin.users.reset', $member) }}" onsubmit="return confirm('Générer un nouveau mot de passe provisoire ?')">@csrf
                                <button class="font-medium text-brand-600 hover:text-brand-800">Réinitialiser le mot de passe</button>
                            </form>
                            <form method="POST" action="{{ route('admin.users.toggle', $member) }}">@csrf
                                <button class="font-medium {{ $member->is_active ? 'text-red-600 hover:text-red-800' : 'text-emerald-700 hover:text-emerald-900' }}">{{ $member->is_active ? 'Désactiver' : 'Réactiver' }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>

        <section class="surface p-6">
            <h2 class="font-display text-lg font-bold">Ajouter un membre</h2>
            <form method="POST" action="{{ route('admin.team.store') }}" class="mt-4 grid gap-4 sm:grid-cols-[1fr_1fr_auto_auto]">
                @csrf
                <input name="name" required class="field !mt-0" placeholder="Nom" value="{{ old('name') }}">
                <input name="email" type="email" required class="field !mt-0" placeholder="E-mail" value="{{ old('email') }}">
                <select name="role" class="field !mt-0" aria-label="Rôle">
                    <option value="admin">Admin</option>
                    <option value="super_admin">Super admin</option>
                </select>
                <button class="btn-primary">Créer</button>
            </form>
            <p class="mt-3 text-xs text-slate-500">Un mot de passe provisoire est affiché une seule fois après la création.</p>
        </section>
    </div>
</x-app-layout>
