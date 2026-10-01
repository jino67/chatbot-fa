<div style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; max-width: 560px;">
    <h2 style="margin: 0 0 8px;">{{ $reminder ? 'Rappel : ' : '' }}{{ $lead->label() }}</h2>
    <p style="margin: 0 0 16px; color: #6b7280;">
        Assistant : <strong>{{ $lead->bot?->name }}</strong>
        @if ($lead->contact_name || $lead->contact_phone) · Client : {{ trim($lead->contact_name.' '.$lead->contact_phone) }} @endif
    </p>

    <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; background: #f9fafb;">
        <p style="margin: 0 0 6px;"><strong>{{ $lead->title }}</strong></p>
        @if ($lead->summary && $lead->summary !== $lead->title)
            <p style="margin: 0;">{{ $lead->summary }}</p>
        @endif
    </div>

    <p style="margin: 20px 0;">
        <a href="{{ $conversationUrl }}" style="background: #2340D9; color: #ffffff; padding: 10px 18px; border-radius: 6px; text-decoration: none; display: inline-block;">Voir la conversation</a>
        <a href="{{ $url }}" style="margin-left: 8px; color: #2340D9;">Toutes les demandes</a>
    </p>
    <p style="color: #9ca3af; font-size: 12px;">Vous choisissez comment être prévenu dans « Alertes », depuis votre espace.</p>
</div>
