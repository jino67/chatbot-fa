@php
    $rows = fn (array $list, string $unit = 'visites') => collect($list)->map(fn ($r) => [
        'label' => $r['label'],
        'value' => $r['sessions'],
        'hint' => \App\Support\StatsFormat::percent($r['conversion_rate']).' aboutissent',
    ])->all();
    $plain = fn (array $list) => collect($list)->map(fn ($r) => ['label' => $r['label'], 'value' => $r['sessions']])->all();
@endphp

<div class="grid gap-4 sm:grid-cols-3">
    <div class="surface p-5"><p class="text-sm text-slate-600">Nouveaux visiteurs</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ \App\Support\StatsFormat::number($overview['new_visitors']) }}</p><p class="mt-1 text-xs text-slate-500">première visite sur ce navigateur</p></div>
    <div class="surface p-5"><p class="text-sm text-slate-600">Visiteurs qui reviennent</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ \App\Support\StatsFormat::number($overview['returning_visitors']) }}</p><p class="mt-1 text-xs text-slate-500">déjà venus avant</p></div>
    <div class="surface p-5"><p class="text-sm text-slate-600">Depuis l'application installée</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ \App\Support\StatsFormat::percent($overview['pwa_share']) }}</p><p class="mt-1 text-xs text-slate-500">des visites</p></div>
</div>

<x-stats.card title="Canaux" hint="« Aboutissent » : la visite se termine par un contact (WhatsApp, e-mail, téléphone) ou une inscription.">
    <x-stats.bars :rows="$rows($sources)" unit="visites" />
</x-stats.card>

<div class="grid gap-6 lg:grid-cols-2">
    <x-stats.card title="Sites qui envoient des visiteurs"><x-stats.bars :rows="$rows($referrers)" unit="visites" empty="Aucun site extérieur pour l'instant : les visites sont directes." /></x-stats.card>
    <x-stats.card title="Campagnes" hint="Les liens marqués utm_campaign (publicité, e-mail, publication)."><x-stats.bars :rows="$rows($campaigns)" unit="visites" empty="Aucune campagne mesurée. Ajoutez ?utm_campaign=nom à vos liens pour les suivre." /></x-stats.card>
    <x-stats.card title="Appareils"><x-stats.bars :rows="$plain($devices)" unit="visites" /></x-stats.card>
    <x-stats.card title="Pays (estimés)" hint="Déduits du fuseau horaire du navigateur : aucune adresse IP n'est lue."><x-stats.bars :rows="$plain($countries)" unit="visites" /></x-stats.card>
    <x-stats.card title="Navigateurs"><x-stats.bars :rows="$plain($browsers)" unit="visites" /></x-stats.card>
    <x-stats.card title="Systèmes"><x-stats.bars :rows="$plain($systems)" unit="visites" /></x-stats.card>
    <x-stats.card title="Langues du navigateur" class="lg:col-span-2"><x-stats.bars :rows="$plain($languages)" unit="visites" /></x-stats.card>
</div>
