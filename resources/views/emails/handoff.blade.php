<div style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; max-width: 560px;">
    <h2 style="margin: 0 0 8px;">Un client attend une réponse humaine</h2>
    <p style="margin: 0 0 16px; color: #6b7280;">
        Assistant : <strong>{{ $conversation->bot->name }}</strong> · Canal : {{ $conversation->channel }} · Client : {{ $conversation->displayName() }}
    </p>

    <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; background: #f9fafb;">
        @foreach ($messages as $message)
            <p style="margin: 6px 0;">
                <strong>{{ $message->role === 'user' ? 'Client' : ($message->role === 'agent' ? 'Équipe' : 'Assistant') }} :</strong>
                {{ $message->content }}
            </p>
        @endforeach
    </div>

    <p style="margin: 20px 0;">
        <a href="{{ $url }}" style="background: #4f46e5; color: #ffffff; padding: 10px 18px; border-radius: 6px; text-decoration: none; display: inline-block;">
            Répondre depuis la boîte de réception
        </a>
    </p>
    <p style="color: #9ca3af; font-size: 12px;">Motif du transfert : {{ $reason }}</p>
</div>
