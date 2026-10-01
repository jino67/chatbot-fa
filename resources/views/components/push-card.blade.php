{{--
    Activation des notifications sur l'appareil en cours : une phrase et un geste selon son état (jamais demandé, bloqué, iPhone
    sans l'application installée, déjà actif). La variante « reminder » est le rappel du tableau de bord : elle ne s'affiche que s'il
    y a quelque chose à faire, se repousse avec « Plus tard » et s'arrête après quelques refus. Logique : resources/js/push.js.
--}}
@props(['reminder' => false])
<section x-data="pushCard({ reminder: @js($reminder) })" x-init="init()" x-show="! hidden && state !== 'loading'" x-cloak
         class="surface relative overflow-hidden p-5 sm:p-6" aria-labelledby="push-title">
    <span class="absolute inset-y-0 left-0 w-1.5 bg-accent-500" aria-hidden="true"></span>
    <div class="flex flex-wrap items-start gap-4 sm:flex-nowrap">
        <span class="mt-0.5 grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-brand-50 text-brand-700"><x-icon name="bell" class="h-6 w-6" /></span>

        <div class="min-w-0 flex-1">
            {{-- Jamais demandé --}}
            <template x-if="state === 'default' || state === 'granted'">
                <div>
                    <h2 id="push-title" class="font-display text-lg font-bold text-brand-950">Recevez vos alertes sur ce téléphone</h2>
                    <p class="mt-1 text-sm leading-relaxed text-slate-600">Une commande, un rendez-vous, un client qui attend une personne : vous êtes prévenu tout de suite, même quand l'application est fermée. Le nombre de messages à lire s'affiche sur l'icône. Vous choisissez ce que vous recevez, et vous pouvez tout arrêter d'un geste.</p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" class="btn-primary" :disabled="busy" @click="enable()"><x-icon name="bell" class="h-4 w-4" /> <span x-text="state === 'granted' ? 'Terminer l\'activation' : 'Activer les notifications'"></span></button>
                        <button type="button" x-show="reminder" class="text-sm font-medium text-slate-500 hover:text-slate-800" @click="later(false)">Plus tard</button>
                    </div>
                </div>
            </template>

            {{-- iPhone et iPad : seulement depuis l'application installée --}}
            <template x-if="state === 'ios-install'">
                <div>
                    <h2 id="push-title" class="font-display text-lg font-bold text-brand-950">Installez l'application pour recevoir les notifications</h2>
                    <p class="mt-1 text-sm leading-relaxed text-slate-600">Sur iPhone et iPad, les notifications ne fonctionnent que depuis l'application installée. Touchez <strong>Partager</strong> dans Safari, puis <strong>« Sur l'écran d'accueil »</strong>. Ouvrez ensuite l'application depuis son icône et revenez à cette page : le bouton d'activation apparaît.</p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" x-show="reminder" class="text-sm font-medium text-slate-500 hover:text-slate-800" @click="later(false)">Me le rappeler plus tard</button>
                    </div>
                </div>
            </template>

            {{-- Bloquées : comment les débloquer, selon l'appareil --}}
            <template x-if="state === 'denied'">
                <div>
                    <h2 id="push-title" class="font-display text-lg font-bold text-brand-950">Les notifications sont bloquées sur cet appareil</h2>
                    <p class="mt-1 text-sm leading-relaxed text-slate-600">Vous risquez de manquer une commande ou un client qui attend. Pour les débloquer :</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm leading-relaxed text-slate-700">
                        <template x-if="platform === 'android'">
                            <li>Touchez le cadenas à gauche de l'adresse (dans l'application installée : maintenez son icône, puis « Infos sur l'appli »), ouvrez <strong>Notifications</strong> et choisissez <strong>Autoriser</strong>.</li>
                        </template>
                        <template x-if="platform === 'ios'">
                            <li>Ouvrez <strong>Réglages</strong>, puis <strong>Notifications</strong>, trouvez l'application dans la liste et activez <strong>Autoriser les notifications</strong>.</li>
                        </template>
                        <template x-if="platform === 'desktop'">
                            <li>Cliquez sur le cadenas à gauche de l'adresse, ouvrez <strong>Paramètres du site</strong>, puis réglez <strong>Notifications</strong> sur <strong>Autoriser</strong>.</li>
                        </template>
                        <li>Revenez sur cette page et touchez « J'ai débloqué, vérifier ».</li>
                    </ol>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" class="btn-primary" :disabled="busy" @click="refresh()">J'ai débloqué, vérifier</button>
                        <button type="button" x-show="reminder" class="text-sm font-medium text-slate-500 hover:text-slate-800" @click="later(true)">Me le rappeler plus tard</button>
                    </div>
                </div>
            </template>

            {{-- Actives --}}
            <template x-if="state === 'subscribed'">
                <div>
                    <h2 id="push-title" class="font-display text-lg font-bold text-brand-950">Notifications activées sur cet appareil</h2>
                    <p class="mt-1 text-sm leading-relaxed text-slate-600">Vous serez prévenu dès qu'un client vous écrit, passe commande ou attend une réponse. Le compteur s'affiche sur l'icône de l'application installée.</p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" class="btn-outline" :disabled="busy" @click="test()">Envoyer un essai</button>
                        <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-800" :disabled="busy" @click="disable()">Désactiver sur cet appareil</button>
                        <a href="{{ route('notifications.preferences') }}" class="text-sm font-semibold text-brand-700 hover:underline">Choisir ce que je reçois</a>
                    </div>
                </div>
            </template>

            {{-- Navigateur sans notifications --}}
            <template x-if="state === 'unsupported'">
                <div>
                    <h2 id="push-title" class="font-display text-lg font-bold text-brand-950">Ce navigateur ne gère pas les notifications</h2>
                    <p class="mt-1 text-sm leading-relaxed text-slate-600">Ouvrez {{ $brand['name'] ?? 'Kouma' }} avec Chrome, Edge, Firefox ou Safari à jour, ou installez l'application sur votre téléphone. En attendant, les alertes continuent d'arriver par e-mail.</p>
                </div>
            </template>

            <p class="mt-3 text-sm font-medium text-brand-700" x-show="message" x-text="message" role="status" aria-live="polite"></p>
        </div>
    </div>
</section>
