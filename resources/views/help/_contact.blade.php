{{--
    Nous contacter, en fin de guide : le chat web, l'e-mail, WhatsApp (si le numéro est réglé) et « on s'occupe de tout ».
    Un moyen non configuré n'apparaît pas. Le chat web est le widget de la page (voir help/index et help/guide).
--}}
@php
    $mail = \App\Support\Contact::mailto('Une question sur Kouma', 'j\'ai une question : ');
    $wa = \App\Support\Contact::whatsappLink('j\'ai une question : ');
    $manage = \App\Support\Contact::whatsappLink('j\'aimerais que vous vous occupiez de tout pour moi.') ?: \App\Support\Contact::mailto('Gérez tout pour moi', 'j\'aimerais que vous vous occupiez de tout pour moi (assistant, documents, WhatsApp). ');
@endphp
<section class="not-prose rounded-3xl rounded-bl-lg border border-slate-200 bg-white p-6 shadow-lift sm:p-8" aria-labelledby="contact-title">
    <h2 id="contact-title" class="font-display text-xl font-bold text-brand-950">Une question ? Nous sommes là.</h2>
    <p class="mt-2 text-slate-600">Choisissez le moyen qui vous arrange. Nous répondons vite, en français, et nous vous guidons jusqu'à ce que ce soit réglé.</p>
    <div class="mt-5 grid gap-3 sm:grid-cols-2">
        @if ($landingBotKey ?? null)
            <button type="button" onclick="window.KoumaWidget && window.KoumaWidget.open()" class="rounded-2xl border border-slate-200 p-4 text-start transition hover:border-brand-300 hover:bg-brand-50/60">
                <span class="block font-semibold text-brand-950">Discuter avec le chat web</span>
                <span class="mt-0.5 block text-sm text-slate-600">La bulle en bas à droite de cette page : réponse immédiate, à toute heure.</span>
            </button>
        @endif
        @if ($mail)
            <a href="{{ $mail }}" class="rounded-2xl border border-slate-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/60">
                <span class="block font-semibold text-brand-950">Écrire par e-mail</span>
                <span class="mt-0.5 block text-sm text-slate-600">{{ \App\Support\Contact::email() }}</span>
            </a>
        @endif
        @if ($wa)
            <a href="{{ $wa }}" target="_blank" rel="noopener" class="rounded-2xl border border-slate-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/60">
                <span class="block font-semibold text-brand-950">Écrire sur WhatsApp</span>
                <span class="mt-0.5 block text-sm text-slate-600">Un message suffit.</span>
            </a>
        @endif
    </div>
    @if ($manage)
        <div class="wax-navy mt-5 flex flex-wrap items-center justify-between gap-4 rounded-2xl p-5 text-white">
            <div class="max-w-lg">
                <p class="font-display font-bold">Vous préférez qu'on s'occupe de tout ?</p>
                <p class="mt-1 text-sm text-white/75">Création de votre assistant, import de vos documents, branchement de WhatsApp, réglages : notre équipe gère tout pour vous. Vous n'avez qu'à valider.</p>
            </div>
            <a href="{{ $manage }}" class="btn-accent shrink-0">Demander qu'on s'en occupe</a>
        </div>
    @endif
</section>
