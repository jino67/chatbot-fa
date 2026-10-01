<x-mail.layout :preheader="'Choisissez un nouveau mot de passe : le lien est valable '.$minutes.' minutes.'" :settings="false" reason="Vous recevez ce message parce qu'une réinitialisation de mot de passe a été demandée pour votre compte.">
    <x-mail.title>Réinitialisation de votre mot de passe</x-mail.title>

    <p>Bonjour{{ $name ? ' '.strtok($name, ' ') : '' }},</p>
    <p>Nous avons reçu une demande pour choisir un nouveau mot de passe sur votre compte {{ $brandName }}. Touchez le bouton ci-dessous pour le faire.</p>

    <div style="margin:10px 0 6px;">
        <x-mail.button :url="$url">Choisir un nouveau mot de passe</x-mail.button>
    </div>

    <p style="margin:16px 0 8px;color:#4B5563;">Ce lien est valable {{ $minutes }} minutes et ne sert qu'une fois.</p>
    <p style="margin:0 0 8px;color:#4B5563;">Vous n'êtes pas à l'origine de cette demande ? Ignorez ce message : votre mot de passe ne change pas.</p>
    <p style="margin:16px 0 0;font-size:12.5px;color:#9CA3AF;">Le bouton ne s'ouvre pas ? Copiez cette adresse dans votre navigateur : <span style="word-break:break-all;">{{ $url }}</span></p>
</x-mail.layout>
