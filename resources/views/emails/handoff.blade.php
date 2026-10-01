<x-mail.layout :preheader="'Motif : '.$reason" tone="warning" reason="Vous recevez ce message parce que vous avez choisi d'être prévenu quand un client veut parler à une personne (page Alertes de votre espace).">
    <x-mail.title tone="warning">Un client attend une réponse humaine</x-mail.title>

    <p>
        Assistant : <strong>{{ $conversation->bot->name }}</strong><br>
        Canal : {{ $conversation->channel }}<br>
        Client : <strong>{{ $conversation->displayName() }}</strong>
    </p>

    <x-mail.box>
        @foreach ($messages as $message)
            <span style="display:block;margin:{{ $loop->first ? '0' : '8px' }} 0 0;">
                <strong>{{ $message->role === 'user' ? 'Client' : ($message->role === 'agent' ? 'Équipe' : 'Assistant') }} :</strong>
                {{ $message->content }}
            </span>
        @endforeach
    </x-mail.box>

    <div style="margin:10px 0 0;">
        <x-mail.button :url="$url">Répondre depuis la boîte de réception</x-mail.button>
    </div>
    <p style="margin:16px 0 0;font-size:13px;color:#6B7280;">Motif du transfert : {{ $reason }}</p>
</x-mail.layout>
