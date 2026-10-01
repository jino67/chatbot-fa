<x-mail.layout :preheader="$lead->title" tone="warning" reason="Vous recevez ce message parce que vous avez choisi d'être prévenu des demandes de vos clients (page Alertes de votre espace).">
    <x-mail.title tone="warning">{{ $reminder ? 'Rappel : ' : '' }}{{ $lead->label() }}</x-mail.title>

    <p>
        Assistant : <strong>{{ $lead->bot?->name }}</strong>
        @if ($lead->contact_name || $lead->contact_phone)<br>Client : <strong>{{ trim($lead->contact_name.' '.$lead->contact_phone) }}</strong>@endif
    </p>

    <x-mail.box>
        <strong>{{ $lead->title }}</strong>
        @if ($lead->summary && $lead->summary !== $lead->title)<br>{{ $lead->summary }}@endif
    </x-mail.box>

    @if ($reminder)
        <p>Cette demande attend toujours une réponse de votre part. Votre client compte sur vous.</p>
    @endif

    <div style="margin:10px 0 0;">
        <x-mail.button :url="$conversationUrl">Voir la conversation</x-mail.button>
        <x-mail.button :url="$url" tone="light">Toutes les demandes</x-mail.button>
    </div>
</x-mail.layout>
