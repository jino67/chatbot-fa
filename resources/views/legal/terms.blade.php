<x-legal-layout title="Conditions d'utilisation">
    <p class="text-sm text-slate-500">Dernière mise à jour : {{ $legal['updated'] }}</p>

    <p>
        Le service {{ $brand['name'] }} est édité par <strong>{{ $legal['company'] }}</strong>, {{ $legal['address'] }}
        (immatriculation : {{ $legal['registration'] }}). Contact : <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a>.
        En créant un compte, vous acceptez les présentes conditions.
    </p>

    <h2>1. Le service</h2>
    <p>
        {{ $brand['name'] }} permet à une entreprise de créer un assistant conversationnel à partir de ses propres documents, photos et liens,
        de l'intégrer à son site web et, sur demande, de le connecter à un numéro WhatsApp. Les réponses sont produites par des modèles d'intelligence artificielle
        à partir des contenus que vous fournissez.
    </p>

    <h2>2. Votre compte</h2>
    <ul>
        <li>Vous fournissez des informations exactes et gardez votre mot de passe confidentiel.</li>
        <li>Vous êtes responsable de l'activité réalisée depuis votre espace.</li>
        <li>Vous nous prévenez sans délai en cas d'utilisation non autorisée.</li>
    </ul>

    <h2>3. Vos contenus</h2>
    <p>
        Vous restez propriétaire des documents, photos, textes et liens que vous ajoutez. Vous nous accordez le droit de les traiter uniquement pour faire fonctionner votre assistant.
        Vous garantissez avoir le droit de les utiliser et ne pas fournir de contenu illégal, trompeur ou portant atteinte aux droits de tiers.
        Les contenus de votre page Facebook ou Instagram sont ajoutés par vous, depuis une page dont vous avez la gestion.
    </p>

    <h2>4. Réponses de l'assistant</h2>
    <p>
        L'assistant est conçu pour ne répondre qu'à partir de vos contenus, mais une réponse automatique peut contenir une erreur.
        Vous restez responsable des informations que vous lui donnez (prix, horaires, conditions) et de leur mise à jour.
        L'assistant ne remplace ni un conseil médical, juridique ou financier, ni un professionnel qualifié.
    </p>

    <h2>5. WhatsApp</h2>
    <p>
        L'utilisation de WhatsApp est soumise aux conditions de Meta (WhatsApp Business). Vous devez avoir le consentement de vos clients pour leur écrire
        et respecter les règles de la plateforme : messages hors fenêtre de 24 heures uniquement par modèles approuvés, pas de messages non sollicités.
        Meta peut suspendre un numéro ; nous ne pouvons pas en être tenus responsables.
    </p>

    <h2>6. Offres, paiement et résiliation</h2>
    <ul>
        <li>Une offre gratuite est disponible. Les offres payantes sont facturées à la période affichée sur la page Abonnement.</li>
        <li>Le paiement se fait par Mobile Money ou virement ; l'offre est activée dès réception du paiement.</li>
        <li>À l'échéance sans renouvellement, une période de grâce de {{ (int) config('platform.billing.grace_days') }} jours s'applique, puis votre espace repasse à l'offre gratuite. Vos contenus ne sont pas supprimés mais les limites de l'offre gratuite s'appliquent.</li>
        <li>Vous pouvez arrêter à tout moment. Les périodes déjà payées ne sont pas remboursées, sauf disposition légale contraire.</li>
    </ul>

    <h2>7. Utilisation acceptable</h2>
    <p>
        Il est interdit d'utiliser le service pour envoyer du spam, usurper une identité, collecter des données personnelles à l'insu des personnes,
        contourner les limites techniques, ou tenter d'accéder à l'espace d'un autre client. Nous pouvons suspendre un espace en cas d'abus.
    </p>

    <h2>8. Disponibilité et responsabilité</h2>
    <p>
        Nous mettons en œuvre les moyens raisonnables pour maintenir le service, sans garantie de disponibilité continue.
        Le service dépend de fournisseurs tiers (intelligence artificielle, hébergement, WhatsApp) dont les interruptions échappent à notre contrôle.
        Notre responsabilité est limitée aux sommes payées au cours des douze derniers mois, dans la mesure permise par la loi.
    </p>

    <h2>9. Modification des conditions</h2>
    <p>Nous pouvons mettre à jour ces conditions ; en cas de changement important, nous vous en informons par e-mail ou dans votre espace.</p>

    <h2>10. Contact</h2>
    <p>Pour toute question : <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a>.</p>
</x-legal-layout>
