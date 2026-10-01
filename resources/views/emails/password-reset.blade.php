<div style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; max-width: 560px; margin: 0 auto;">
    <p style="margin: 0 0 18px; font-size: 22px; font-weight: bold; color: #2340D9;">{{ $brandName }}</p>

    <h2 style="margin: 0 0 12px; font-size: 18px;">Réinitialisation de votre mot de passe</h2>
    <p style="margin: 0 0 14px; line-height: 1.5;">Bonjour{{ $name ? ' '.$name : '' }},</p>
    <p style="margin: 0 0 20px; line-height: 1.5;">Nous avons reçu une demande pour choisir un nouveau mot de passe sur votre compte {{ $brandName }}. Touchez le bouton ci-dessous pour le faire.</p>

    <p style="margin: 0 0 24px;">
        <a href="{{ $url }}" style="background: #2340D9; color: #ffffff; padding: 12px 22px; border-radius: 999px; text-decoration: none; display: inline-block; font-weight: bold;">Choisir un nouveau mot de passe</a>
    </p>

    <p style="margin: 0 0 14px; line-height: 1.5; color: #4b5563;">Ce lien est valable {{ $minutes }} minutes et ne sert qu'une fois.</p>
    <p style="margin: 0 0 14px; line-height: 1.5; color: #4b5563;">Vous n'êtes pas à l'origine de cette demande ? Ignorez ce message : votre mot de passe ne change pas.</p>

    <p style="margin: 24px 0 0; padding-top: 14px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 12px; line-height: 1.5;">
        Le bouton ne s'ouvre pas ? Copiez cette adresse dans votre navigateur :<br>
        <span style="word-break: break-all;">{{ $url }}</span>
    </p>
</div>
