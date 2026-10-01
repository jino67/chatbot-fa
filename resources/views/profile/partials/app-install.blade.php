<section>
    <header class="flex items-start gap-4">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-50 transition-all duration-300 group-hover:rounded-2xl group-hover:rounded-bl-sm"><x-illus name="phone" class="h-9 w-9" /></span>
        <div>
            <h2 class="font-display text-lg font-bold text-brand-950">Utiliser {{ $brand['name'] }} comme une application</h2>
            <p class="mt-1 text-sm text-slate-600">Une icône sur l'écran d'accueil ouvre vos demandes et vos conversations en un geste, sans rien télécharger.</p>
        </div>
    </header>

    @if ($user->pwa_installed_at)
        <div class="mt-5 flex items-start gap-3 rounded-xl rounded-bl-sm border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
            <span>C'est fait : vous avez ouvert {{ $brand['name'] }} comme une application le {{ $user->pwa_installed_at->translatedFormat('j F Y') }}. L'invitation ne s'affiche plus pour vous. Pour l'installer sur un autre appareil, suivez les étapes ci-dessous.</span>
        </div>
    @endif

    <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl rounded-bl-md bg-slate-50 p-5">
            <p class="text-sm font-semibold text-brand-950">iPhone et iPad (Safari)</p>
            <ol class="mt-2 list-decimal space-y-1.5 ps-5 text-sm text-slate-600">
                <li>Touchez le bouton <strong class="font-semibold text-slate-800">Partager</strong>.</li>
                <li>Choisissez <strong class="font-semibold text-slate-800">« Sur l'écran d'accueil »</strong>.</li>
                <li>Touchez <strong class="font-semibold text-slate-800">Ajouter</strong>.</li>
            </ol>
            <p class="mt-2 text-xs text-slate-500">Depuis Facebook, Instagram ou WhatsApp, ouvrez d'abord le site dans Safari.</p>
        </div>
        <div class="rounded-2xl rounded-bl-md bg-slate-50 p-5">
            <p class="text-sm font-semibold text-brand-950">Android et ordinateur (Chrome, Edge)</p>
            <ol class="mt-2 list-decimal space-y-1.5 ps-5 text-sm text-slate-600">
                <li>Ouvrez le menu du navigateur <strong class="font-semibold text-slate-800">⋮</strong>.</li>
                <li>Choisissez <strong class="font-semibold text-slate-800">« Installer l'application »</strong> ou « Ajouter à l'écran d'accueil ».</li>
                <li>Sur ordinateur, l'icône d'installation se trouve aussi à droite de la barre d'adresse.</li>
            </ol>
        </div>
    </div>

    @unless ($user->pwa_installed_at)
        <div class="mt-5">
            <button type="button" class="btn-primary px-5 py-2.5" onclick="window.dispatchEvent(new Event('kouma-install-hint'))">Me guider sur cet appareil</button>
        </div>
    @endunless
</section>
