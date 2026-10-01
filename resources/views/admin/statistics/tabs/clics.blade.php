@php
    $rows = fn (array $list, string $key = 'name') => collect($list)->map(fn ($r) => ['label' => $r[$key] ?: '(sans nom)', 'value' => $r['clicks'], 'hint' => ($r['page'] ?? null) ? $r['page'].', '.$r['visitors'].' visiteur(s)' : $r['visitors'].' visiteur(s)'])->all();
    $contactNames = ['whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'telephone' => 'Téléphone'];
    $contacts = collect($clicks['contacts'])->map(fn ($r) => ['label' => $contactNames[$r['name']] ?? $r['name'], 'value' => $r['clicks'], 'hint' => $r['visitors'].' visiteur(s)'])->all();
    $sections = collect($clicks['sections'])->map(fn ($r) => ['label' => $r['name'], 'value' => $r['sessions']])->all();
@endphp

<div class="grid gap-6 lg:grid-cols-2">
    <x-stats.card title="Boutons d'action les plus cliqués" hint="Les gros boutons : créer un compte, voir les offres, écrire sur WhatsApp.">
        <x-stats.bars :rows="$rows($clicks['cta'])" unit="clics" empty="Aucun clic sur un bouton d'action pour l'instant." />
    </x-stats.card>

    <x-stats.card title="Contacts" hint="Quel moyen de vous écrire les visiteurs choisissent.">
        <x-stats.bars :rows="$contacts" unit="clics" empty="Personne n'a encore cliqué sur un lien de contact." />
    </x-stats.card>

    <x-stats.card title="Tous les clics sur liens et boutons" class="lg:col-span-2">
        <x-stats.bars :rows="$rows($clicks['clicks'])" unit="clics" />
    </x-stats.card>

    <x-stats.card title="Sections lues" hint="Combien de visites ont vu chaque grande section de la page d'accueil (au moins la moitié visible pendant une seconde).">
        <x-stats.bars :rows="$sections" unit="visites" empty="Les sections lues apparaîtront avec les prochaines visites." />
    </x-stats.card>

    <x-stats.card title="Liens vers d'autres sites">
        <x-stats.bars :rows="$rows($clicks['outbound'])" unit="clics" empty="Aucun clic vers un autre site." />
    </x-stats.card>

    <x-stats.card title="Téléchargements de documents" hint="Les guides en PDF.">
        <x-stats.bars :rows="$rows($clicks['downloads'])" unit="clics" empty="Aucun document téléchargé." />
    </x-stats.card>

    <x-stats.card title="Clics répétés, signe de frustration" hint="Trois clics ou plus au même endroit en moins de deux secondes : l'élément ne répond sans doute pas comme le visiteur l'attend.">
        <x-stats.bars :rows="$rows($clicks['rage'])" unit="fois" empty="Aucun clic répété. C'est bon signe." />
    </x-stats.card>
</div>
