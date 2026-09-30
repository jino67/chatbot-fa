<x-app-layout title="Nouvel espace client | {{ $brand['name'] }}">
    <x-slot name="header">
        <a href="{{ route('admin.workspaces.index') }}" class="text-sm text-slate-500 hover:text-brand-700">Espaces clients</a>
        <div class="mt-1"><x-page-header title="Nouvel espace client" subtitle="Créez l'espace et le compte du client. Vous pourrez ensuite y entrer pour créer son assistant et ses contenus." /></div>
    </x-slot>

    <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
        <form method="POST" action="{{ route('admin.workspaces.store') }}" class="surface space-y-5 p-6">
            @csrf
            <div>
                <x-input-label for="name" value="Nom de l'entreprise" />
                <input id="name" name="name" required class="field" value="{{ old('name') }}">
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="owner_name" value="Nom du responsable" />
                    <input id="owner_name" name="owner_name" required class="field" value="{{ old('owner_name') }}">
                </div>
                <div>
                    <x-input-label for="owner_email" value="E-mail du responsable" />
                    <input id="owner_email" name="owner_email" type="email" required class="field" value="{{ old('owner_email') }}">
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="country" value="Pays" />
                    <input id="country" name="country" class="field" value="{{ old('country') }}">
                </div>
                <div>
                    <x-input-label for="phone" value="Téléphone" />
                    <input id="phone" name="phone" class="field" value="{{ old('phone') }}">
                </div>
            </div>
            <div>
                <x-input-label for="plan" value="Offre de départ" />
                <select id="plan" name="plan" class="field">
                    @foreach ($plans as $p)
                        <option value="{{ $p->slug }}" @selected(old('plan', 'free') === $p->slug)>{{ $p->name }} ({{ $p->formattedPrice() }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">Une offre payante choisie ici est offerte sans échéance. Pour enregistrer un paiement, utilisez la fiche de l'espace après création.</p>
            </div>
            <p class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700">Un mot de passe provisoire est généré et affiché une seule fois après la création : transmettez-le au client, qui pourra le changer.</p>
            <div class="flex justify-end gap-3">
                <a href="{{ route('admin.workspaces.index') }}" class="btn-outline">Annuler</a>
                <button class="btn-primary">Créer l'espace</button>
            </div>
        </form>
    </div>
</x-app-layout>
