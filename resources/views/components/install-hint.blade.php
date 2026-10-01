{{--
    Invitation à installer le site comme une application, dans l'espace connecté : sur iPhone et iPad il faut passer par
    le menu Partager de Safari (les étapes sont montrées), sur Android et ordinateur le navigateur propose un bouton
    d'installation. Pour ne pas importuner :
    - elle n'apparaît qu'une fois par visite, et s'arrête d'elle-même après quatre apparitions ignorées ;
    - « Plus tard » la repousse de deux semaines, « Ne plus afficher » la coupe pour de bon ;
    - dès que l'application est ouverte en mode application (icône sur l'écran d'accueil), elle le signale au serveur
      (users.pwa_installed_at) : l'invitation ne revient plus, même dans Safari, qui ne peut pas le savoir seul ;
    - une personne qui l'a déjà installée ne reçoit plus le composant du tout.
--}}
@php $installed = (bool) auth()->user()?->pwa_installed_at; @endphp
@unless ($installed)
<div x-data="installHint(@js(route('pwa.installed')))" x-init="init()" x-show="visible" x-cloak
     x-transition:enter="transition duration-500 ease-[cubic-bezier(0.2,1.2,0.3,1)]" x-transition:enter-start="translate-y-6 opacity-0"
     class="fixed inset-x-3 bottom-3 z-[60] sm:left-auto sm:right-4 sm:max-w-sm" role="dialog" aria-label="Installer l'application">
    <div class="rounded-2xl rounded-bl-md bg-brand-950 p-4 text-white shadow-pop ring-1 ring-white/10">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-600"><x-mark tone="light" class="h-7 w-7" /></span>
            <div class="min-w-0 flex-1">
                <p class="font-display text-sm font-bold">Installez {{ $brand['name'] ?? 'Kouma' }} sur votre écran d'accueil</p>
                <p class="mt-0.5 text-xs text-white/70">Ouvrez vos demandes en un geste, sans rien télécharger. Une fois installée, activez les notifications : vous serez alerté à la seconde d'une commande, même application fermée.</p>
            </div>
            <button type="button" @click="later()" class="rounded-full p-1 text-white/60 hover:bg-white/10 hover:text-white" aria-label="Fermer"><x-icon name="x" class="h-4 w-4" /></button>
        </div>

        {{-- iPhone et iPad : étapes du menu Partager --}}
        <ol x-show="mode === 'ios'" class="mt-3 space-y-2 rounded-xl bg-white/10 p-3 text-xs leading-snug">
            <li class="flex items-start gap-2">
                <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-accent-500 text-[11px] font-bold text-brand-950">1</span>
                <span>Touchez le bouton <strong>Partager</strong>
                    <svg viewBox="0 0 20 20" class="mx-0.5 inline h-4 w-4 align-text-bottom" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 12.5V3M6.5 6.5 10 3l3.5 3.5M5 9H4.5A1.5 1.5 0 0 0 3 10.5v6A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5v-6A1.5 1.5 0 0 0 15.5 9H15"/></svg>
                    <span x-text="ipad ? 'en haut de Safari' : 'en bas de Safari'"></span>.</span>
            </li>
            <li class="flex items-start gap-2">
                <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-accent-500 text-[11px] font-bold text-brand-950">2</span>
                <span>Faites défiler et choisissez <strong>« Sur l'écran d'accueil »</strong>
                    <svg viewBox="0 0 20 20" class="mx-0.5 inline h-4 w-4 align-text-bottom" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="3" y="3" width="14" height="14" rx="3"/><path d="M10 6.8v6.4M6.8 10h6.4"/></svg>.</span>
            </li>
            <li class="flex items-start gap-2">
                <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-accent-500 text-[11px] font-bold text-brand-950">3</span>
                <span>Touchez <strong>Ajouter</strong> : l'icône apparaît avec vos autres applications.</span>
            </li>
            <li x-show="inApp" class="rounded-lg bg-accent-500/20 px-2 py-1.5 text-[11px] text-accent-100">Vous êtes dans une application (Facebook, Instagram, WhatsApp...) : ouvrez d'abord ce site dans <strong>Safari</strong>.</li>
        </ol>

        {{-- Android sans bouton automatique --}}
        <ol x-show="mode === 'android' || mode === 'generic'" class="mt-3 space-y-2 rounded-xl bg-white/10 p-3 text-xs leading-snug">
            <li>Ouvrez le menu du navigateur <strong>⋮</strong>, puis choisissez <strong>« Installer l'application »</strong> ou <strong>« Ajouter à l'écran d'accueil »</strong>.</li>
        </ol>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button x-show="mode === 'prompt'" type="button" @click="install()" class="btn-accent px-4 py-2 text-xs">Installer l'application</button>
            <button type="button" @click="later()" class="rounded-full px-3 py-1.5 text-xs font-medium text-white/80 hover:bg-white/10">Plus tard</button>
            <button type="button" @click="never()" class="rounded-full px-3 py-1.5 text-xs text-white/50 hover:bg-white/10 hover:text-white/80">Ne plus afficher</button>
        </div>
    </div>
</div>

<script>
    function installHint(reportUrl) {
        const KEY = 'kouma-install';
        const SEEN = 'kouma-install-seen';
        const read = () => { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; } };
        const write = (data) => { try { localStorage.setItem(KEY, JSON.stringify(data)); } catch (e) { /* stockage bloqué : l'invitation reviendra */ } };
        const seenThisVisit = () => { try { return sessionStorage.getItem(SEEN) === '1'; } catch (e) { return false; } };

        return {
            visible: false,
            mode: null,
            deferred: null,
            ipad: false,
            ios: false,
            inApp: false,

            init() {
                const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
                if (standalone) { this.report(); return; }

                const ua = navigator.userAgent;
                this.ipad = /iPad/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                this.ios = this.ipad || /iPhone|iPod/.test(ua);
                this.inApp = /FBAN|FBAV|Instagram|Line\/|MicroMessenger|WhatsApp/i.test(ua);

                // Ces écouteurs restent actifs même si l'invitation est coupée : le profil peut la rappeler à la demande.
                window.addEventListener('beforeinstallprompt', (event) => {
                    event.preventDefault();
                    this.deferred = event;
                    if (this.allowed()) { this.mode = 'prompt'; this.reveal(); }
                });
                window.addEventListener('appinstalled', () => { this.visible = false; write({ ...read(), never: true }); this.report(); });
                window.addEventListener('kouma-install-hint', () => this.force());

                if (!this.allowed()) return;

                if (this.ios) {
                    this.mode = 'ios';
                    this.reveal();
                } else if (/Android/.test(ua)) {
                    // Chrome envoie « beforeinstallprompt » quelques instants après le chargement ; sinon on montre les étapes.
                    setTimeout(() => { if (!this.deferred) { this.mode = 'android'; this.reveal(); } }, 3500);
                }
            },

            allowed() {
                const saved = read();
                return !(saved.never || (saved.until && saved.until > Date.now()) || seenThisVisit());
            },

            // Signale au serveur que l'application est installée : la personne n'est plus invitée, sur aucun appareil.
            report() {
                const token = document.querySelector('meta[name="csrf-token"]');
                fetch(reportUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token ? token.content : '', 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});
            },

            reveal() {
                setTimeout(() => {
                    if (this.visible || seenThisVisit()) return;
                    this.visible = true;
                    try { sessionStorage.setItem(SEEN, '1'); } catch (e) { /* une fois par page alors */ }
                    const saved = read();
                    saved.seen = (saved.seen || 0) + 1;
                    if (saved.seen >= 4) saved.never = true;
                    write(saved);
                }, 1500);
            },

            // Demandée depuis le profil (« Me guider sur cet appareil ») : on oublie les refus passés et on montre les étapes.
            force() {
                write({});
                this.mode = this.deferred ? 'prompt' : (this.ios ? 'ios' : (/Android/.test(navigator.userAgent) ? 'android' : 'generic'));
                this.visible = true;
            },

            async install() {
                if (!this.deferred) return;
                this.deferred.prompt();
                await this.deferred.userChoice.catch(() => null);
                this.deferred = null;
                this.visible = false;
            },

            later() { this.visible = false; write({ ...read(), until: Date.now() + 14 * 86400000 }); },

            never() { this.visible = false; write({ ...read(), never: true }); },
        };
    }
</script>
@endunless
