{{--
    « Besoin d'aide ? » : le guide, le chat web de Kouma, l'e-mail et WhatsApp (ces deux-là seulement s'ils sont réglés
    dans les Paramètres de la plateforme), et la proposition « on s'occupe de tout ». Chaque lien emporte le contexte
    (espace, compte, page). Deux présentations : « sidebar » (compacte, menu de gauche) et « page » (carte du tableau de bord).
--}}
@props(['variant' => 'page'])
@php
    $help = \App\Support\Contact::mailto('Besoin d\'aide', 'j\'ai besoin d\'aide pour : ');
    $manage = \App\Support\Contact::mailto('Gérez tout pour moi', "j'aimerais que vous vous occupiez de tout (assistant, documents, WhatsApp). Voici ce que je vends ou propose : ");
    $helpWa = \App\Support\Contact::whatsappLink('j\'ai besoin d\'aide pour : ');
    $manageWa = \App\Support\Contact::whatsappLink('j\'aimerais que vous vous occupiez de tout pour moi (assistant, documents, WhatsApp).');
    $chat = \App\Support\Contact::chatKey();
@endphp

@once
    @if ($chat)
        <script>
            // Ouvre le chat web de Kouma sans l'installer en permanence : le script ne se charge qu'au clic.
            window.koumaHelpChat = function () {
                if (window.KoumaWidget) { window.KoumaWidget.open(); return; }
                var s = document.createElement('script');
                s.src = @js(url('/widget/widget.js'));
                s.async = true;
                s.setAttribute('data-bot', @js($chat));
                s.setAttribute('data-open', 'true');
                document.body.appendChild(s);
            };
        </script>
    @endif
@endonce

@if ($variant === 'sidebar')
    <details class="group mb-3 rounded-lg bg-white/10 text-sm open:bg-white/15">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2.5 font-semibold marker:hidden">
            <x-icon name="chat" class="h-4 w-4 text-accent-400" /> Besoin d'aide ?
            <x-icon name="arrow-right" class="ms-auto h-3.5 w-3.5 text-white/60 transition-transform group-open:rotate-90" />
        </summary>
        <div class="space-y-1 px-2 pb-2.5">
            <a href="{{ route('help.client') }}" class="block rounded-md px-2 py-1.5 text-white/80 hover:bg-white/10 hover:text-white">Le guide d'utilisation</a>
            @if ($chat) <button type="button" onclick="koumaHelpChat()" class="block w-full rounded-md px-2 py-1.5 text-start text-white/80 hover:bg-white/10 hover:text-white">Discuter avec Kouma (chat)</button> @endif
            @if ($help) <a href="{{ $help }}" class="block rounded-md px-2 py-1.5 text-white/80 hover:bg-white/10 hover:text-white">Écrire par e-mail</a> @endif
            @if ($helpWa) <a href="{{ $helpWa }}" target="_blank" rel="noopener" class="block rounded-md px-2 py-1.5 text-white/80 hover:bg-white/10 hover:text-white">Écrire sur WhatsApp</a> @endif
            @if ($manage || $manageWa)
                <a href="{{ $manageWa ?: $manage }}" @if ($manageWa) target="_blank" rel="noopener" @endif class="mt-1 block rounded-md bg-accent-500 px-2 py-1.5 text-center font-semibold text-brand-950 hover:bg-accent-400">On s'occupe de tout pour vous</a>
            @endif
        </div>
    </details>
@else
    <section class="surface overflow-hidden" aria-labelledby="help-title">
        <div class="grid gap-0 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="p-6">
                <h2 id="help-title" class="font-display text-lg font-bold">Besoin d'aide ?</h2>
                <p class="mt-1 text-sm text-slate-600">Une question, un doute, un blocage : choisissez ce qui vous arrange. Nous répondons vite.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <a href="{{ route('help.client') }}" class="group rounded-xl border border-slate-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/50">
                        <p class="text-sm font-semibold text-brand-950">Le guide d'utilisation</p>
                        <p class="mt-0.5 text-xs text-slate-600">Pas à pas, avec les réponses aux questions fréquentes.</p>
                    </a>
                    @if ($chat)
                        <button type="button" onclick="koumaHelpChat()" class="rounded-xl border border-slate-200 p-4 text-start transition hover:border-brand-300 hover:bg-brand-50/50">
                            <p class="text-sm font-semibold text-brand-950">Discuter avec Kouma</p>
                            <p class="mt-0.5 text-xs text-slate-600">Le chat web répond tout de suite, à toute heure.</p>
                        </button>
                    @endif
                    @if ($help)
                        <a href="{{ $help }}" class="rounded-xl border border-slate-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/50">
                            <p class="text-sm font-semibold text-brand-950">Nous écrire par e-mail</p>
                            <p class="mt-0.5 text-xs text-slate-600">{{ \App\Support\Contact::email() }}</p>
                        </a>
                    @endif
                    @if ($helpWa)
                        <a href="{{ $helpWa }}" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/50">
                            <p class="text-sm font-semibold text-brand-950">Nous écrire sur WhatsApp</p>
                            <p class="mt-0.5 text-xs text-slate-600">Un message suffit, nous répondons dans la journée.</p>
                        </a>
                    @endif
                </div>
            </div>
            <div class="wax-navy flex flex-col justify-center p-6 text-white">
                <p class="font-display text-lg font-bold">Vous préférez qu'on gère tout ?</p>
                <p class="mt-1 text-sm text-white/75">Assistant, documents, WhatsApp, réglages : notre équipe s'occupe de tout pour vous, vous n'avez qu'à valider.</p>
                @if ($manage || $manageWa)
                    <a href="{{ $manageWa ?: $manage }}" @if ($manageWa) target="_blank" rel="noopener" @endif class="btn-accent mt-4 self-start">Demander qu'on s'en occupe</a>
                @endif
            </div>
        </div>
    </section>
@endif
