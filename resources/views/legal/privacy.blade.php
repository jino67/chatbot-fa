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
        <li><strong>Notifications</strong> : si vous activez les notifications sur un appareil, nous gardons l'adresse technique que le navigateur nous donne pour vous écrire (jamais votre numéro), le type d'appareil et la date d'activation, ainsi que vos préférences. Vous les retirez à tout moment depuis « Préférences de notifications » ou en bloquant les notifications dans votre navigateur ; elles cessent aussi à la déconnexion. Les e-mails que nous envoyons sont des messages de service (alertes, échéances, reçus, mot de passe) ; les promotions peuvent être refusées dans vos préférences.</li>
    </ul>

    <h2>3. Finalités</h2>
    <ul>
        <li>Faire fonctionner l'assistant et lui permettre de répondre à partir des contenus de l'entreprise.</li>
        <li>Transmettre une conversation à un conseiller humain, et prévenir l'entreprise.</li>
        <li>Gérer les comptes, abonnements et paiements.</li>
        <li>Sécuriser le service, prévenir les abus et mesurer la consommation.</li>
        <li>
            Assurer la qualité du service et l'assistance : l'équipe de la plateforme peut lire des conversations (pour comprendre une panne ou une réclamation, ou pour améliorer un assistant).
            Cet accès est réservé à des personnes habilitées, les numéros de téléphone des clients des entreprises y sont partiellement masqués, et chaque consultation est inscrite dans un journal.
        </li>
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
    <p>
        Nous utilisons des cookies techniques nécessaires à la connexion et à la sécurité (session, protection contre les requêtes frauduleuses),
        et trois petits cookies de mesure d'audience décrits ci-dessous. Aucun cookie publicitaire, aucun pistage d'un site à l'autre.
    </p>

    <h2 id="mesure">10. Mesure d'audience</h2>
    <p>
        Pour savoir combien de personnes visitent {{ $brand['name'] }}, quelles pages elles lisent, sur quoi elles cliquent et à quelle heure,
        nous mesurons nous-mêmes les visites, sans service tiers et sans transmettre ces données à qui que ce soit. L'objectif est d'améliorer le site et l'application.
    </p>
    <ul>
        <li><strong>Ce qui est enregistré</strong> : les pages vues, les clics sur les liens et boutons (leur libellé, jamais ce que vous écrivez), jusqu'où vous faites défiler la page, le temps passé, le type d'appareil, de navigateur et de système, la langue, le site d'où vous venez, un pays estimé d'après le fuseau horaire de votre navigateur, et les erreurs rencontrées.</li>
        <li><strong>Ce qui n'est jamais enregistré</strong> : votre adresse IP, ce que vous saisissez dans un champ, le contenu de vos conversations avec un assistant, votre nom, votre e-mail ou votre numéro (s'ils apparaissent dans un libellé, ils sont effacés avant l'enregistrement).</li>
        <li><strong>Les cookies de mesure</strong> : <code>_kv</code> (un identifiant aléatoire, 13 mois, pour compter les visiteurs qui reviennent), <code>_ks</code> (un identifiant de visite, effacé à la fermeture du navigateur) et <code>_ko</code> (votre refus, s'il y en a un). Ils ne servent à rien d'autre et ne sont lus par aucun autre site.</li>
        <li><strong>Pour un compte client connecté</strong> : les actions faites dans l'application (créer un assistant, ajouter une source, répondre à un client...) sont rattachées à l'espace pour que nous sachions quelles fonctions servent et où aider. Seule l'équipe de {{ $brand['name'] }} les voit, jamais les autres clients.</li>
        <li><strong>Conservation</strong> : les données de mesure sont effacées automatiquement au bout de {{ app(\App\Services\Analytics\Tracker::class)->retentionDays() }} jours.</li>
    </ul>
    <p>
        Votre navigateur peut aussi vous protéger : si « Ne pas suivre » ou « Global Privacy Control » est activé, nous ne mesurons rien.
        Vous pouvez de plus refuser ici, à tout moment :
    </p>
    <div x-data="{ off: false, signal: false }"
         x-init="signal = navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true; off = !!(window.koumaOptedOut && window.koumaOptedOut())"
         class="not-prose my-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
        <template x-if="signal">
            <p>Votre navigateur envoie déjà le signal « Ne pas suivre » : <strong>aucune mesure n'est faite pour vous</strong>.</p>
        </template>
        <template x-if="!signal && !off">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p>La mesure est <strong>active</strong> sur ce navigateur.</p>
                <button type="button" class="btn-outline" @click="window.koumaOptOut(true); off = true">Ne plus me mesurer</button>
            </div>
        </template>
        <template x-if="!signal && off">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p>Votre refus est enregistré : <strong>vous n'êtes plus mesuré</strong> sur ce navigateur.</p>
                <button type="button" class="btn-outline" @click="window.koumaOptOut(false); off = false">Accepter à nouveau</button>
            </div>
        </template>
    </div>
</x-legal-layout>
