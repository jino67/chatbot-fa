<x-bot-layout :bot="$bot" tab="channels">
    @php
        $requestOpen = $request && in_array($request->status, ['requested', 'in_progress']);
    @endphp

    {{-- Partager le lien de discussion --}}
    <section id="partager" class="surface mb-6 space-y-4 p-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-display text-lg font-bold">Partager votre assistant</h2>
            <x-badge tone="green">Sans rien installer</x-badge>
        </div>
        <x-share-chat :bot="$bot" />
    </section>

    <div class="grid gap-6 lg:grid-cols-2">

        {{-- Site web --}}
        <section class="surface min-w-0 space-y-4 p-6">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-lg font-bold">Votre site web</h2>
                <x-badge tone="green">Disponible</x-badge>
            </div>
            <p class="text-sm text-slate-600">Copiez cette ligne et collez-la juste avant la balise <code class="rounded bg-slate-100 px-1">&lt;/body&gt;</code> de votre site. Une bulle de discussion apparaît, aux couleurs de votre assistant.</p>

            <div x-data="{ copied: false }" class="relative">
                <pre class="overflow-x-auto rounded-lg bg-brand-950 p-4 text-xs leading-relaxed text-slate-100"><code x-ref="code">{{ $snippet }}</code></pre>
                <button type="button"
                        @click="navigator.clipboard.writeText($refs.code.textContent); copied = true; setTimeout(() => copied = false, 1800)"
                        class="absolute right-2 top-2 rounded bg-white/10 px-2 py-1 text-xs text-white hover:bg-white/20" x-text="copied ? 'Copié' : 'Copier'"></button>
            </div>

            @php $check = session('install_check'); @endphp
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <h3 class="text-sm font-semibold text-brand-950">Vérifier que la bulle est bien installée</h3>
                <p class="mt-1 text-xs text-slate-600">Indiquez l'adresse d'une page de votre site : nous l'ouvrons comme un visiteur et vous disons si le script y est, et sinon pourquoi.</p>
                <form method="POST" action="{{ route('channels.check-install', $bot) }}" class="mt-3 flex flex-wrap gap-2">
                    @csrf
                    <input name="url" type="text" required maxlength="300" value="{{ old('url', $check['url'] ?? '') }}" placeholder="https://votre-site.com" class="field !mt-0 min-w-0 flex-1" aria-label="Adresse de votre site">
                    <button class="btn-primary">Vérifier mon site</button>
                </form>
                <x-input-error :messages="$errors->get('url')" class="mt-1" />
                @if ($check)
                    <div @class(['mt-3 rounded-lg px-4 py-3 text-sm', 'bg-emerald-50 text-emerald-900' => $check['ok'], 'bg-red-50 text-red-900' => ! $check['ok']])>
                        <p class="font-semibold">{{ $check['ok'] ? '✓' : '✗' }} {{ $check['title'] }}</p>
                        <p class="mt-1">{{ $check['help'] }}</p>
                    </div>
                @endif
                <p class="mt-3 text-xs text-slate-500">
                    @if ($widgetSeen)
                        Dernier chargement de la bulle : <strong>{{ $widgetSeen['origin'] ?: 'un site' }}</strong>, {{ \Illuminate\Support\Carbon::parse($widgetSeen['at'])->diffForHumans() }}.
                    @else
                        Aucun chargement de la bulle détecté pour l'instant : une fois le script en ligne, ouvrez une page de votre site et revenez ici.
                    @endif
                </p>
            </div>

            <ul class="list-disc space-y-1 ps-5 text-sm text-slate-600">
                <li><strong>WordPress</strong> : extension « Insert Headers and Footers », zone « Footer ».</li>
                <li><strong>Shopify</strong> : Boutique en ligne, Thèmes, Modifier le code, <code>theme.liquid</code>.</li>
                <li><strong>Wix / Squarespace</strong> : « Code personnalisé » en pied de page.</li>
                <li><strong>Site fait main</strong> : collez la ligne avant la fermeture de <code>&lt;body&gt;</code>.</li>
            </ul>

            @if (empty($bot->allowed_origins))
                <div class="rounded-lg border border-accent-300 bg-accent-50 px-4 py-3 text-sm text-accent-800">
                    Avant la mise en ligne, indiquez votre domaine dans <a class="font-medium underline" href="{{ route('bots.edit', $bot) }}">Réglages</a> pour empêcher un autre site d'utiliser votre assistant.
                </div>
            @endif

            <a href="{{ route('demo', $bot->public_key) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-800">
                Ouvrir la page de démonstration à partager <x-icon name="external" class="h-3.5 w-3.5" />
            </a>
        </section>

        {{-- WhatsApp --}}
        <section class="surface min-w-0 space-y-4 p-6">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-lg font-bold">WhatsApp</h2>
                @if ($whatsapp?->isActive()) <x-badge tone="green">Actif</x-badge>
                @elseif ($requestOpen) <x-badge tone="blue">{{ $request->statusLabel() }}</x-badge>
                @elseif (! $whatsappAllowed) <x-badge tone="amber">Offre supérieure</x-badge>
                @else <x-badge>Non activé</x-badge> @endif
            </div>

            @if ($whatsapp?->isActive())
                <p class="text-sm text-slate-700">
                    Votre assistant répond aux clients qui écrivent au <strong>{{ $whatsapp->display_phone }}</strong>.
                    Il propose des boutons de réponse rapide et vous pouvez reprendre la main depuis l'onglet « Conversations ».
                </p>
                <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
                    <p class="font-medium text-brand-950">Écrire à un client après 24 heures</p>
                    <p class="mt-1">WhatsApp impose un modèle de message approuvé au-delà de 24 heures sans réponse du client (relance, suivi de commande, rappel de rendez-vous).</p>
                    @if ($templatesAllowed)
                        <a href="{{ route('templates.index', $bot) }}" class="btn-primary mt-3">Gérer mes modèles de messages</a>
                    @else
                        <a href="{{ route('billing.show') }}" class="btn-accent mt-3">Débloquer les modèles avec l'offre Pro</a>
                    @endif
                </div>
            @elseif (! $whatsappAllowed)
                <p class="text-sm text-slate-600">
                    WhatsApp est inclus à partir de l'offre Essentiel. Notre équipe technique s'occupe de tout : compte WhatsApp Business, vérification, configuration.
                </p>
                <a href="{{ route('billing.show') }}" class="btn-accent">Voir les offres</a>
            @elseif ($requestOpen)
                <p class="text-sm text-slate-700">
                    Votre demande pour le numéro <strong>{{ $request->phone_number }}</strong> est en cours de traitement par notre équipe technique.
                    Nous vous contacterons si des informations complémentaires sont nécessaires.
                </p>
                @if ($request->admin_notes)
                    <div class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700">{{ $request->admin_notes }}</div>
                @endif
            @else
                <p class="text-sm text-slate-600">
                    L'activation de WhatsApp est réalisée par notre équipe technique (compte WhatsApp Business, vérification, configuration). Indiquez-nous le numéro à utiliser.
                </p>
                @if ($request && $request->status === 'rejected')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        Votre précédente demande n'a pas pu aboutir. {{ $request->admin_notes }}
                    </div>
                @endif
                <form method="POST" action="{{ route('channels.whatsapp-request', $bot) }}" class="space-y-4">
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="business_name" value="Nom de l'entreprise (tel qu'affiché)" />
                            <input id="business_name" name="business_name" required class="field" value="{{ old('business_name', $bot->company()) }}">
                        </div>
                        <div>
                            <x-input-label for="phone_number" value="Numéro WhatsApp souhaité" />
                            <input id="phone_number" name="phone_number" required class="field" placeholder="+226 70 00 00 00" value="{{ old('phone_number') }}">
                        </div>
                    </div>
                    <div>
                        <x-input-label for="country" value="Pays" />
                        <input id="country" name="country" class="field" value="{{ old('country') }}">
                    </div>
                    <div>
                        <x-input-label for="notes" value="Précisions (numéro déjà utilisé sur WhatsApp Business ? page Facebook liée ?)" />
                        <textarea id="notes" name="notes" rows="3" class="field">{{ old('notes') }}</textarea>
                    </div>
                    <button class="btn-primary">Demander l'activation</button>
                </form>
            @endif
        </section>
    </div>
</x-bot-layout>
