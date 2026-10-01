{{ $brandName }} : réinitialisation de votre mot de passe

Bonjour{{ $name ? ' '.$name : '' }},

Nous avons reçu une demande pour choisir un nouveau mot de passe sur votre compte {{ $brandName }}.
Ouvrez ce lien pour le faire (valable {{ $minutes }} minutes, une seule fois) :

{{ $url }}

Vous n'êtes pas à l'origine de cette demande ? Ignorez ce message : votre mot de passe ne change pas.
