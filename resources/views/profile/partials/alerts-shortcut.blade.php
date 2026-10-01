<section>
    <header class="flex items-start gap-4">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-50 transition-all duration-300 group-hover:rounded-2xl group-hover:rounded-bl-sm"><x-illus name="bell" class="h-9 w-9" /></span>
        <div>
            <h2 class="font-display text-lg font-bold text-brand-950">Mes alertes</h2>
            <p class="mt-1 text-sm text-slate-600">Quand un client demande une personne, ou qu'une commande attend votre confirmation, vous êtes prévenu : c'est vous qui choisissez comment.</p>
        </div>
    </header>

    <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
        <div class="rounded-xl bg-slate-50 px-4 py-3">
            <dt class="text-slate-500">Par e-mail</dt>
            <dd class="mt-0.5 break-all font-medium text-slate-800">{{ $user->email }}</dd>
        </div>
        <div class="rounded-xl bg-slate-50 px-4 py-3">
            <dt class="text-slate-500">Sur mon téléphone (WhatsApp)</dt>
            <dd class="mt-0.5 font-medium text-slate-800">
                @if ($user->phone)
                    {{ $user->phone }}
                @else
                    <span class="font-normal text-slate-600">Pas de numéro : ajoutez-le dans <a href="#infos" class="font-medium text-brand-700 underline decoration-brand-300 underline-offset-2">Mes informations</a>.</span>
                @endif
            </dd>
        </div>
    </dl>

    <div class="mt-5">
        <a href="{{ route('alerts.edit') }}" class="btn-outline px-5 py-2.5">Régler mes alertes</a>
    </div>
</section>
