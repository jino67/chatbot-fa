{{--
    Appareil pliable de démonstration, à essayer pour de vrai (exemple fictif). Format « passeport » : plus large
    que haut une fois ouvert, écran de couverture à l'extérieur, grand écran à l'intérieur.
      - À gauche, ce que voit le client : on écrit ou on dicte un message, l'assistant répond.
      - À droite, ce que voit la gérante : les demandes arrivent, elle choisit comment être prévenue et répond.
      - Replié, l'écran de couverture affiche les notifications ; on plie et on déplie avec le bouton, la charnière, un
        glissement du doigt ou de la souris, ou en touchant la notification.
    Une histoire se joue d'abord toute seule ; au premier geste du visiteur, elle s'arrête et il prend la main.
    Moteur : resources/js/playground (chargé quand l'appareil est visible), dialogue local sans réseau.
    Sans JavaScript ou sans animation : les blocs [data-static] montrent l'histoire terminée.
--}}
@props(['shop' => 'Boutique Awa', 'owner' => 'Awa'])
<div data-fold-play {{ $attributes->merge(['class' => 'fold relative mx-auto w-full max-w-[31.5rem]']) }}>

    <div class="fold-tilt" data-fold-tilt>
        <div data-fold class="fold-device mx-auto" data-state="open">
            {{-- Moitié gauche : la conversation du client --}}
            <div class="fold-half fold-left">
                <div class="fold-frame">
                    <div class="fold-screen bg-slate-100 text-brand-950">
                        <div data-pg-speaker class="flex shrink-0 items-center gap-2.5 bg-brand-700 px-3 py-2 text-white">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white"><x-mark class="h-5 w-5" /></span>
                            <div class="min-w-0 leading-tight">
                                <p class="truncate text-[13px] font-semibold">{{ $shop }}</p>
                                <p class="flex items-center gap-1.5 text-[11px] text-white/80"><span class="h-1.5 w-1.5 rounded-full bg-feuille-400"></span><span data-pg-status>assistant en ligne</span></p>
                            </div>
                        </div>

                        <div data-pg-body aria-live="polite" class="pg-scroll flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto px-2.5 py-3 text-[12.5px] leading-snug">
                            <div class="grow" aria-hidden="true"></div>

                            <div data-static class="ms-auto max-w-[84%] rounded-2xl rounded-br-sm bg-brand-100 px-3 py-1.5">Bonsoir, vous livrez à Bobo ?</div>
                            <div data-static class="max-w-[90%] rounded-2xl rounded-bl-sm bg-white px-3 py-1.5 shadow-sm">Oui, à Bobo-Dioulasso : <strong>2 000 FCFA</strong>, livré sous 48 h. Gratuit dès 25 000 FCFA d'achat.</div>
                            <div data-static class="ms-auto max-w-[84%] rounded-2xl rounded-br-sm bg-brand-100 px-3 py-1.5">Je prends 2 boubous brodés.</div>
                            <div data-static class="max-w-[90%] rounded-2xl rounded-bl-sm bg-white px-3 py-1.5 shadow-sm">2 boubous brodés × 35 000 FCFA = <strong>70 000 FCFA</strong>. Livraison offerte, car la commande dépasse 25 000 FCFA. Je confirme ?</div>
                            <div data-static class="ms-auto max-w-[84%] rounded-2xl rounded-br-sm bg-brand-100 px-3 py-1.5">Je veux parler à {{ $owner }}</div>
                            <div data-static class="max-w-[90%] rounded-2xl rounded-bl-sm bg-white px-3 py-1.5 shadow-sm">Bien sûr, je préviens <strong>{{ $owner }}</strong> : elle vous répond ici.</div>

                            <div data-pg-typing hidden class="dots flex w-fit gap-1 rounded-2xl rounded-bl-sm bg-white px-3 py-2.5 shadow-sm" aria-hidden="true">
                                <i class="h-1.5 w-1.5 rounded-full bg-slate-400"></i><i class="h-1.5 w-1.5 rounded-full bg-slate-400"></i><i class="h-1.5 w-1.5 rounded-full bg-slate-400"></i>
                            </div>
                        </div>

                        {{-- Réponses rapides proposées par l'assistant --}}
                        <div data-pg-chips class="pg-scroll flex shrink-0 gap-1.5 overflow-x-auto px-2.5 pb-2 pt-0.5"></div>

                        <form data-pg-form data-no-loader autocomplete="off" class="flex h-[3.25rem] shrink-0 items-center gap-1.5 border-t border-slate-200 bg-white px-2">
                            <input data-pg-input type="text" maxlength="200" enterkeyhint="send" aria-label="Votre message au client"
                                   placeholder="Message…"
                                   class="pg-input h-8 min-w-0 flex-1 rounded-full border-0 bg-slate-100 px-3.5 text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-brand-500">
                            <button type="button" data-pg-mic aria-label="Envoyer un message vocal (exemple)" title="Message vocal (exemple)"
                                    class="pg-round grid h-8 w-8 shrink-0 place-items-center bg-slate-100 text-slate-600 transition hover:bg-brand-100 hover:text-brand-700 max-[520px]:h-7 max-[520px]:w-7">
                                <svg viewBox="0 0 20 20" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="7.5" y="2.5" width="5" height="9" rx="2.5"/><path d="M4.5 9.5a5.5 5.5 0 0 0 11 0M10 15v2.5"/></svg>
                            </button>
                            <button type="submit" aria-label="Envoyer"
                                    class="pg-round grid h-8 w-8 shrink-0 place-items-center bg-brand-600 text-white transition hover:bg-brand-700 active:scale-95 max-[520px]:h-7 max-[520px]:w-7">
                                <svg viewBox="0 0 20 20" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 16V4M5 9l5-5 5 5"/></svg>
                            </button>
                        </form>
                    </div>
                    <span class="fold-camera" aria-hidden="true"></span>
                </div>
            </div>

            {{-- Moitié droite : la boîte de réception de la gérante. Repliée, son dos devient l'écran de couverture. --}}
            <div class="fold-half fold-right">
                <div class="fold-face fold-front">
                    <div class="fold-frame">
                        <div class="fold-screen bg-slate-50 text-brand-950">
                            <div class="flex shrink-0 items-center justify-between gap-2 border-b border-slate-200 bg-white py-2 pl-3 pr-9">
                                <div class="min-w-0 leading-tight">
                                    <p class="truncate text-[10.5px] text-slate-500 max-[520px]:hidden">Espace {{ $shop }}</p>
                                    <p class="truncate font-display text-[12.5px] font-bold"><span class="max-[520px]:hidden">Vos demandes</span><span class="min-[521px]:hidden">Demandes</span></p>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span data-pg-count class="grid h-6 min-w-6 place-items-center rounded-full bg-hibiscus-500 px-1.5 text-[11px] font-bold text-white transition-transform" aria-label="Demandes en attente">2</span>
                                    <button type="button" data-pg-gear aria-expanded="false" aria-label="Choisir comment être prévenue" title="Comment être prévenue ?"
                                            class="pg-round grid h-7 w-7 place-items-center bg-slate-100 text-slate-600 transition hover:bg-brand-100 hover:text-brand-700">
                                        <svg viewBox="0 0 20 20" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3.2 11.1 5a1 1 0 0 0 .9.5l2 .1.7 2-1.4 1.5a1 1 0 0 0 0 1.2l1.4 1.5-.7 2-2 .1a1 1 0 0 0-.9.5L10 16.8 8.9 15a1 1 0 0 0-.9-.5l-2-.1-.7-2 1.4-1.5a1 1 0 0 0 0-1.2L5.3 8l.7-2 2-.1a1 1 0 0 0 .9-.5Z"/><circle cx="10" cy="10" r="2.2"/></svg>
                                    </button>
                                </div>
                            </div>

                            {{-- Choix des alertes : chaque interrupteur change vraiment ce qui se passe à la prochaine demande --}}
                            <div data-pg-settings hidden class="pg-pop-in absolute inset-x-2.5 top-[3.2rem] z-20 rounded-2xl rounded-tr-sm border border-slate-200 bg-white p-3 shadow-pop">
                                <p class="text-[12px] font-semibold">Comment être prévenue ?</p>
                                <p class="mt-0.5 text-[10.5px] leading-snug text-slate-500">Une commande à confirmer ou une demande d'une personne : choisissez vos alertes.</p>
                                <div class="mt-2 space-y-1.5">
                                    @foreach ([['whatsapp', 'WhatsApp', 'Message sur votre téléphone'], ['email', 'E-mail', 'Un e-mail à chaque demande'], ['dashboard', 'Tableau de bord', 'Pastille rouge et carte en haut']] as [$key, $label, $help])
                                        <label class="flex cursor-pointer items-center justify-between gap-2">
                                            <span class="min-w-0 leading-tight"><span class="block text-[11.5px] font-medium">{{ $label }}</span><span class="block truncate text-[10px] text-slate-500">{{ $help }}</span></span>
                                            <input type="checkbox" role="switch" data-pg-notify="{{ $key }}" checked class="pg-switch">
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div data-pg-toasts class="pointer-events-none absolute inset-x-2.5 top-[3.2rem] z-10 flex flex-col gap-1.5"></div>

                            <div data-pg-leads class="pg-scroll min-h-0 flex-1 space-y-2 overflow-y-auto p-2.5">
                                <div data-pg-empty hidden class="rounded-xl border border-dashed border-slate-300 p-4 text-center text-[11.5px] leading-snug text-slate-500">
                                    Rien à traiter pour l'instant. Écrivez à gauche : les commandes à confirmer et les demandes d'une personne arrivent ici.
                                </div>

                                <div data-static class="rounded-xl rounded-bl-sm bg-white p-3 shadow-sm ring-1 ring-hibiscus-500/40">
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-hibiscus-500/10 px-2 py-0.5 text-[10.5px] font-semibold text-hibiscus-600"><span class="h-1.5 w-1.5 rounded-full bg-hibiscus-500"></span>Demande une personne</span>
                                        <span class="shrink-0 text-[10px] text-slate-400">à l'instant</span>
                                    </div>
                                    <p class="mt-1.5 text-[12px] font-semibold">Fatou, +226 70 12 34 56</p>
                                    <p class="text-[11.5px] text-slate-500">Veut parler à {{ $owner }} avant de confirmer.</p>
                                </div>
                                <div data-static class="rounded-xl rounded-bl-sm bg-white p-3 shadow-sm ring-1 ring-accent-400/60">
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-accent-100 px-2 py-0.5 text-[10.5px] font-semibold text-accent-700"><span class="h-1.5 w-1.5 rounded-full bg-accent-500"></span>Commande à confirmer</span>
                                        <span class="shrink-0 text-[10px] text-slate-400">il y a 1 min</span>
                                    </div>
                                    <p class="mt-1.5 text-[12px] font-semibold">2 boubous brodés : 70 000 FCFA</p>
                                    <p class="text-[11.5px] text-slate-500">2 × 35 000 FCFA, livraison offerte à Bobo-Dioulasso.</p>
                                </div>
                            </div>

                            <form data-pg-owner data-no-loader autocomplete="off" class="flex h-[3.25rem] shrink-0 items-center gap-1.5 border-t border-slate-200 bg-white px-2">
                                <input data-pg-owner-input type="text" maxlength="200" disabled aria-label="Votre réponse à la cliente"
                                       placeholder="En attente…"
                                       class="pg-input h-8 min-w-0 flex-1 rounded-full border-0 bg-slate-100 px-3.5 text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-70">
                                <button type="submit" data-pg-owner-send disabled aria-label="Envoyer la réponse"
                                        class="pg-round grid h-8 w-8 shrink-0 place-items-center bg-brand-600 text-white transition hover:bg-brand-700 active:scale-95 disabled:bg-slate-300 max-[520px]:h-7 max-[520px]:w-7">
                                    <svg viewBox="0 0 20 20" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 16V4M5 9l5-5 5 5"/></svg>
                                </button>
                            </form>
                        </div>
                        <span class="fold-punch" aria-hidden="true"></span>
                    </div>
                </div>

                {{-- Dos : l'écran de couverture, visible quand l'appareil est replié ; toucher une notification le déplie --}}
                <div class="fold-face fold-back">
                    <div class="fold-frame fold-frame-cover">
                        <button type="button" data-pg-cover class="fold-screen fold-cover w-full cursor-pointer text-start" aria-label="Déplier l'appareil pour répondre">
                            <span class="fold-island" aria-hidden="true"></span>
                            <span data-pg-clock class="mt-9 block w-full text-center font-display text-4xl font-bold tracking-tight">9:41</span>
                            <span data-pg-date class="block w-full text-center text-[11px] text-white/70">mardi 30 septembre</span>
                            <span data-pg-cover-notes class="mx-2.5 mt-5 flex flex-col gap-1.5">
                                <span data-static class="flex items-start gap-2.5 rounded-2xl bg-white/15 p-2.5 backdrop-blur-md">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-white"><x-mark class="h-5 w-5" /></span>
                                    <span class="min-w-0 text-[11.5px] leading-snug"><span class="block font-semibold">{{ $shop }}</span><span class="block text-white/80">Nouveau message : « Bonsoir, vous livrez à Bobo ? »</span></span>
                                </span>
                            </span>
                            <span data-pg-cover-hint class="mt-auto block w-full pb-4 text-center text-[11px] text-white/60">Touchez pour déplier</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Charnière : capot en titane micro-sablé, mat, visible au centre (ouvert) ou sur la tranche (replié) --}}
            <span class="fold-hinge" aria-hidden="true"></span>
            <button type="button" data-pg-hinge class="fold-hinge-hit" aria-label="Plier ou déplier l'appareil"></button>
            {{-- Éclat de lumière sur le titane poli, il suit le pointeur --}}
            <span class="fold-glare" aria-hidden="true"></span>
            <span class="fold-shine" aria-hidden="true"></span>
        </div>
    </div>

    <div class="fold-ground" aria-hidden="true"></div>

    <div class="fold-captions mt-3 flex justify-around text-center text-[11px] text-white/75" aria-hidden="true">
        <span class="w-1/2">Ce que voit votre client</span>
        <span class="w-1/2">Ce que vous voyez</span>
    </div>

    {{-- Commandes : plier, rejouer, repartir de zéro. Sans JavaScript elles restent cachées. --}}
    <div data-pg-controls hidden class="mt-4 flex flex-wrap items-center justify-center gap-2">
        <button type="button" data-pg-toggle class="pg-ctl">Plier l'appareil</button>
        <button type="button" data-pg-replay class="pg-ctl">Rejouer l'histoire</button>
        <button type="button" data-pg-reset class="pg-ctl">Repartir de zéro</button>
    </div>
    <p data-pg-hint hidden class="mx-auto mt-3 max-w-sm text-center text-xs leading-relaxed text-white/80"></p>
</div>
