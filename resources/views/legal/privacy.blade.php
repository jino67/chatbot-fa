<x-legal-layout title="Politique de confidentialité" description="Quelles données sont collectées, pourquoi, combien de temps elles sont conservées, et comment exercer vos droits sur vos informations et celles de vos clients.">
    <p class="text-sm text-slate-500">Dernière mise à jour : {{ $legal['updated'] }}</p>

    <p>
        Le responsable du traitement est <strong>{{ $legal['company'] }}</strong>, {{ $legal['address'] }}.
        Contact : <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a>.
        Cette page explique quelles données {{ $brand['name'] }} traite, pourquoi, et comment vous gardez la main dessus.
    </p>

    <h2>1. Deux catégories de personnes</h2>
    <ul>
        <li><strong>Nos clients</strong> (les entreprises qui créent un assistant) : nous sommes responsables du traitement de leurs données de compte.</li>
        <li><strong>Les visiteurs et clients de nos clients</strong> (ceux qui discutent avec un assistant) : notre client est responsable du traitement, nous agissons pour son compte en tant que sous-traitant.</li>
    </ul>

    <h2>2. Données traitées</h2>
    <ul>
        <li><strong>Compte</strong> : nom, adresse e-mail, mot de passe (stocké sous forme chiffrée irréversible), nom de l'entreprise.</li>
        <li><strong>Contenus de l'entreprise</strong> : documents, photos, textes et pages web fournis pour alimenter l'assistant.</li>
        <li><strong>Conversations</strong> : messages échangés avec l'assistant, et, si l'entreprise l'a activé, le nom, le téléphone ou l'e-mail donnés par le visiteur. Sur WhatsApp, le numéro de téléphone du client.</li>
        <li><strong>Facturation</strong> : offre, montants, moyen et référence de paiement Mobile Money ou virement. Nous ne stockons aucun numéro de carte bancaire.</li>
        <li><strong>Technique</strong> : journaux de sécurité et d'utilisation (adresse IP, dates, erreurs), nécessaires à la protection du service.</li>
    </ul>

    <h2>3. Finalités</h2>
    <ul>
        <li>Faire fonctionner l'assistant et lui permettre de répondre à partir des contenus de l'entreprise.</li>
        <li>Transmettre une conversation à un conseiller humain, et prévenir l'entreprise.</li>
        <li>Gérer les comptes, abonnements et paiements.</li>
        <li>Sécuriser le service, prévenir les abus et mesurer la consommation.</li>
    </ul>

    <h2>4. Intelligence artificielle et sous-traitants</h2>
    <p>
        Pour produire une réponse, les extraits pertinents des contenus de l'entreprise et la question du visiteur sont envoyés à un fournisseur de modèle d'intelligence artificielle
        (Anthropic, OpenAI, ou un hébergeur de modèles Llama selon la configuration). Ces contenus ne servent pas à entraîner ces modèles dans le cadre de l'accès par interface professionnelle que nous utilisons.
        Les messages WhatsApp transitent par Meta ou Twilio. Notre hébergeur stocke la base de données.
        Ces prestataires peuvent se situer hors de votre pays ; nous choisissons des fournisseurs qui appliquent des garanties contractuelles adaptées.
    </p>

    <h2>5. Facebook et Instagram</h2>
    <p>
        Nous ne lisons pas les pages Facebook ou Instagram de manière automatique. Le contenu est ajouté par l'entreprise elle-même, ou importé par une connexion officielle
        qu'elle autorise explicitement. Elle peut retirer cette connexion et supprimer les contenus importés à tout moment.
    </p>

    <h2>6. Durée de conservation</h2>
    <p>
        Les contenus et conversations sont conservés tant que le compte est actif, puis supprimés dans un délai de 90 jours après sa fermeture, sauf obligation légale de conservation
        (notamment des données de facturation). Une entreprise peut supprimer une source, une conversation ou son assistant à tout moment depuis son espace.
    </p>

    <h2>7. Vos droits</h2>
    <p>
        Vous pouvez demander l'accès, la rectification, l'effacement ou la limitation du traitement de vos données, et vous opposer à certains traitements.
        Écrivez-nous à <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a>. Si vous avez discuté avec l'assistant d'une entreprise, adressez-vous d'abord à cette entreprise.
    </p>

    <h2>8. Sécurité</h2>
    <p>
        Chaque entreprise dispose d'un espace isolé. Les clés d'accès aux services tiers sont chiffrées, les échanges passent par HTTPS,
        les accès du personnel à un espace client sont consignés dans un journal d'audit.
    </p>

    <h2>9. Cookies</h2>
    <p>Nous utilisons uniquement des cookies techniques nécessaires à la connexion et à la sécurité (session, protection contre les requêtes frauduleuses). Aucun cookie publicitaire.</p>
</x-legal-layout>
