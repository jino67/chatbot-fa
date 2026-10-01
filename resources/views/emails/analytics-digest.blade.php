<x-mail.layout :preheader="\App\Support\StatsFormat::number($visits).' visite(s) du '.$from.' au '.$to" reason="Ce résumé part chaque lundi. Vous pouvez l'arrêter dans Administration, Paramètres, Statistiques." :settings="false">
    <x-mail.title>Votre semaine sur {{ $brandName }}</x-mail.title>

    <p style="margin:0 0 4px;color:#6B7280;">Du {{ $from }} au {{ $to }}.</p>
    <p style="margin:0 0 6px;font-size:34px;font-weight:bold;line-height:1.2;color:#2340D9;">{{ \App\Support\StatsFormat::number($visits) }} <span style="font-size:15px;font-weight:normal;color:#374151;">visite(s)</span></p>
    <p style="margin:0 0 18px;color:#374151;">
        {{ \App\Support\StatsFormat::number($visitors) }} visiteur(s) différent(s), {{ \App\Support\StatsFormat::number($pageviews) }} pages vues.
        @if ($delta !== null) {{ \App\Support\StatsFormat::delta($delta) }} par rapport à la semaine d'avant. @endif
        {{ \App\Support\StatsFormat::percent($bounce) }} des visites se terminent sans aucun geste.
    </p>

    @if ($peak)
        <p><strong>Le plus chargé :</strong> {{ mb_strtolower($peak['day']) }} vers {{ $peak['hour'] }} h ({{ $peak['count'] }} visites sur ce créneau).</p>
    @endif

    @if (count($sources))
        <p style="margin:0 0 4px;"><strong>D'où ils viennent</strong></p>
        <ul style="margin:0 0 16px;padding-left:20px;">
            @foreach ($sources as $source) <li>{{ $source['label'] }} : {{ $source['sessions'] }} visite(s)</li> @endforeach
        </ul>
    @endif

    @if (count($pages))
        <p style="margin:0 0 4px;"><strong>Pages les plus vues</strong></p>
        <ul style="margin:0 0 16px;padding-left:20px;">
            @foreach ($pages as $page) <li>{{ $page['name'] }} : {{ $page['views'] }} vue(s)</li> @endforeach
        </ul>
    @endif

    @if ($cta)
        <p><strong>Bouton le plus cliqué :</strong> « {{ $cta['name'] }} » ({{ $cta['clicks'] }} clic(s)).</p>
    @endif

    @if (count($insights))
        <x-mail.box>
            <strong>À retenir</strong>
            @foreach ($insights as $insight)
                <span style="display:block;margin-top:8px;"><strong>{{ $insight['title'] }}.</strong> {{ $insight['text'] }}</span>
            @endforeach
        </x-mail.box>
    @endif

    <p style="margin:0 0 4px;"><strong>Vos clients</strong></p>
    <p style="color:#374151;">
        {{ $active['wau'] }} espace(s) actif(s) cette semaine, {{ $active['mau'] }} ce mois, sur {{ $active['workspaces'] }}.
        @if (count($risk)) À relancer : {{ collect($risk)->pluck('name')->implode(', ') }}. @endif
    </p>

    <div style="margin:14px 0 0;">
        <x-mail.button :url="$url">Ouvrir les statistiques</x-mail.button>
    </div>
</x-mail.layout>
