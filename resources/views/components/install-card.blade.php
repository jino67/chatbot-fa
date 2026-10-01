{{--
    Carte du tableau de bord : pourquoi installer l'application sur le téléphone. Les notifications (commandes, clients qui
    attendent, compteur sur l'icône) fonctionnent même application fermée ; sur iPhone, seule l'application installée peut les recevoir.
    Elle disparaît d'elle-même dès que l'application a été ouverte en mode application (users.pwa_installed_at), et
    « Plus tard » la repousse d'une semaine. Le bouton rappelle les étapes d'installation (voir x-install-hint).
--}}
@unless (auth()->user()?->pwa_installed_at)
    <section x-data="{ hidden: false }"
             x-init="try { const until = Number(localStorage.getItem('kouma-install-card')); hidden = until > Date.now(); } catch (e) {}"
             x-show="! hidden" x-cloak
             class="rounded-2xl rounded-bl-md border border-brand-200 bg-brand-50 px-5 py-4 text-sm" aria-labelledby="install-card-title">
        <div class="flex flex-wrap items-center gap-4">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-600"><x-mark tone="light" class="h-8 w-8" /></span>
            <div class="min-w-0 flex-1 basis-72">
                <p id="install-card-title" class="font-display font-bold text-brand-950">Installez {{ $brand['name'] ?? 'Kouma' }} sur votre téléphone</p>
                <p class="mt-0.5 text-slate-700">
                    Vous serez prévenu(e) à la seconde d'une commande ou d'un client qui attend, directement sur votre téléphone, même quand le site est fermé, avec le nombre de messages à lire sur l'icône.
                    Installez l'application (sur iPhone, c'est la seule façon de recevoir les notifications), puis touchez « Activer les notifications » et acceptez.
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <button type="button" class="btn-primary px-4 py-2" onclick="window.dispatchEvent(new Event('kouma-install-hint'))">Comment installer</button>
                <button type="button" class="rounded-full px-3 py-2 text-sm font-medium text-slate-600 hover:bg-white"
                        @click="hidden = true; try { localStorage.setItem('kouma-install-card', String(Date.now() + 7 * 86400000)); } catch (e) {}">Plus tard</button>
            </div>
        </div>
    </section>
@endunless
