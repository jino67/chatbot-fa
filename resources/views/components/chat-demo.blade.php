{{--
    Conversation d'exemple, jouee en boucle par resources/js/motion.js : le client tape, l'assistant « ecrit »
    (la marque ouvre la bouche), les boutons de reponse rapide apparaissent, puis la gerante est prevenue.
    Sans JavaScript ou avec « reduire les animations », tous les messages restent visibles, en clair.
--}}
@props(['shop' => 'Boutique Awa', 'owner' => 'Awa'])
<div data-chat-demo {{ $attributes->merge(['class' => 'relative mx-auto w-full max-w-sm']) }}
     role="img" aria-label="Exemple de conversation : un client demande la livraison et le prix d'un boubou, l'assistant répond avec les tarifs de la boutique, puis prévient la gérante quand le client veut parler à quelqu'un.">
    <div class="rounded-[2rem] rounded-bl-lg bg-brand-950 p-2.5 shadow-pop ring-1 ring-white/15">
        <div class="overflow-hidden rounded-[1.5rem] rounded-bl-md bg-slate-100 text-brand-950">
            <div data-chat-speaker class="flex items-center gap-3 bg-brand-700 px-4 py-3 text-white">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white"><x-mark class="h-7 w-7" /></span>
                <div class="leading-tight">
                    <p class="text-sm font-semibold">{{ $shop }}</p>
                    <p class="flex items-center gap-1.5 text-xs text-white/80"><span class="h-1.5 w-1.5 rounded-full bg-feuille-400"></span>assistant en ligne</p>
                </div>
            </div>

            <div data-chat-body class="demo-body h-[23rem] px-3 py-4 text-[13.5px] leading-snug">
                <div class="grow" aria-hidden="true"></div>

                <div data-say="user" class="demo-user ms-auto max-w-[80%] rounded-2xl rounded-br-sm bg-brand-100 px-3.5 py-2">Bonsoir, vous livrez à Bobo ?</div>

                <div data-say="bot" data-typing="1100" class="demo-bot max-w-[86%] rounded-2xl rounded-bl-sm bg-white px-3.5 py-2 shadow-sm">
                    Oui, à Bobo-Dioulasso : <strong>2 000 FCFA</strong>, livré sous 48 h. Gratuit dès 25 000 FCFA d'achat.
                </div>

                <div data-say="user" class="demo-user ms-auto max-w-[80%] rounded-2xl rounded-br-sm bg-brand-100 px-3.5 py-2">Le boubou brodé, c'est combien ?</div>

                <div data-say="bot" data-typing="1300" class="demo-bot max-w-[86%] rounded-2xl rounded-bl-sm bg-white px-3.5 py-2 shadow-sm">
                    Le boubou brodé pour homme coûte <strong>35 000 FCFA</strong>. Je vous prépare la commande ?
                    <span class="mt-1.5 flex flex-wrap gap-1.5">
                        <span class="rounded-full border border-brand-200 px-2.5 py-1 text-[11.5px] font-semibold text-brand-700">Commander</span>
                        <span class="rounded-full border border-brand-200 px-2.5 py-1 text-[11.5px] font-semibold text-brand-700">Autres modèles</span>
                    </span>
                    <span class="mt-2 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">Source : Catalogue et prix</span>
                </div>

                <div data-say="user" class="demo-user ms-auto max-w-[80%] rounded-2xl rounded-br-sm bg-brand-100 px-3.5 py-2">Je veux parler à {{ $owner }}</div>

                <div data-say="bot" data-typing="900" class="demo-bot max-w-[86%] rounded-2xl rounded-bl-sm bg-white px-3.5 py-2 shadow-sm">Bien sûr, je préviens {{ $owner }} : elle vous répond ici.</div>

                <div data-chat-typing class="demo-bot demo-pending dots flex w-fit gap-1 rounded-2xl rounded-bl-sm bg-white px-3.5 py-3 shadow-sm" aria-hidden="true">
                    <i class="h-1.5 w-1.5 rounded-full bg-slate-400"></i><i class="h-1.5 w-1.5 rounded-full bg-slate-400"></i><i class="h-1.5 w-1.5 rounded-full bg-slate-400"></i>
                </div>
            </div>

            <div class="flex items-center gap-2 border-t border-slate-200 bg-white px-3 py-2.5" aria-hidden="true">
                <div class="min-h-[2.25rem] min-w-0 flex-1 truncate rounded-full bg-slate-100 px-4 py-2 text-[13px] text-slate-800">
                    <span data-chat-typed></span><span data-chat-placeholder class="text-slate-400">Écrivez votre message…</span>
                </div>
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-600 text-white">
                    <svg viewBox="0 0 20 20" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 16V4M5 9l5-5 5 5"/></svg>
                </span>
            </div>
        </div>
    </div>

    {{-- Moment final : la gerante est prevenue --}}
    <div data-chat-toast class="demo-toast absolute -right-3 top-24 flex items-center gap-2.5 rounded-2xl rounded-bl-md bg-white px-3.5 py-2.5 text-sm text-brand-950 shadow-pop sm:-right-10" aria-hidden="true">
        <span class="grid h-8 w-8 place-items-center rounded-full bg-accent-500 text-brand-950">
            <svg viewBox="0 0 20 20" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 3a5 5 0 0 0-5 5v3l-1.5 3h13L15 11V8a5 5 0 0 0-5-5ZM8 16.5a2 2 0 0 0 4 0"/></svg>
        </span>
        <span class="leading-tight"><strong class="block font-semibold">{{ $owner }} est prévenue</strong><span class="text-xs text-slate-500">Elle répond dans la même discussion</span></span>
    </div>
</div>
