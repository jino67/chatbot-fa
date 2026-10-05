@use('App\Support\StatsFormat', 'N')
@php
    $query = $filters->toQuery();
    $eligible = $audience['eligible']->count();
    $remainingToday = max(0, $cap - $sentToday);
    $thisBatch = $channel === 'email' ? min($batch, $remainingToday, $eligible) : min($batch, $eligible);
@endphp
<x-app-layout title="Écrire à un groupe | Utilisateurs | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Écrire à un groupe" subtitle="Le même message, personnalisé pour chacun, à toute une sélection de la page Utilisateurs. Par petits lots, pour garder une bonne réputation d'envoi.">
            <x-slot name="actions">
                <a href="{{ route('admin.people.index', $query) }}" class="btn-outline">Retour à la liste</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status')) <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div> @endif
        @if (session('error')) <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800" role="alert">{{ session('error') }}</div> @endif
        @if ($errors->any()) <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div> @endif

        <section class="surface p-6">
            <h2 class="font-display text-lg font-bold text-brand-950">{{ $segmentLabel }} : {{ N::number($audience['selected']) }} personne(s)</h2>
            <p class="mt-1 text-sm text-slate-600"><strong class="text-brand-950">{{ N::number($eligible) }}</strong> peuvent recevoir ce message.
                @foreach ($audience['excluded'] as $reason => $n) {{ $loop->first ? 'Écartés :' : '' }} {{ $n }} {{ $exclusions[$reason] }}{{ $loop->last ? '.' : ',' }} @endforeach
            </p>
            <p class="mt-2 text-xs text-slate-500">Un envoi part par lots de {{ $batch }} personnes : après chaque lot, ceux qui viennent d'être contactés sortent de la sélection (pas de nouveau message avant {{ $cooldown }} jours), et vous cliquez à nouveau pour continuer.
                @if ($channel === 'email') Plafond du jour : {{ $sentToday }} e-mail(s) envoyé(s) sur {{ $cap }}. Un domaine récent qui envoie soudain beaucoup de messages est classé en indésirables : montez le volume peu à peu (réglage <code>PEOPLE_DAILY_EMAIL_CAP</code>). @endif
            </p>
        </section>

        {{-- Moyen et modèle : on recharge la page pour reprendre le texte du modèle choisi --}}
        <form method="GET" action="{{ route('admin.people.bulk') }}" class="surface grid gap-4 p-6 sm:grid-cols-2" x-data x-on:change="$el.submit()">
            @foreach ($query as $key => $value) <input type="hidden" name="{{ $key }}" value="{{ $value }}"> @endforeach
            <div>
                <label for="canal" class="text-xs font-medium text-slate-600">Moyen</label>
                <select id="canal" name="canal" class="field"><option value="email" @selected($channel === 'email')>E-mail</option><option value="push" @selected($channel === 'push')>Notification (cloche et téléphone)</option></select>
                <p class="mt-1 text-xs text-slate-500">Pas de WhatsApp en masse : il se fait personne par personne, depuis la fiche.</p>
            </div>
            <div>
                <label for="modele" class="text-xs font-medium text-slate-600">Modèle</label>
                <select id="modele" name="modele" class="field">@foreach ($templates as $key => $label)<option value="{{ $key }}" @selected($template === $key)>{{ $label }}</option>@endforeach</select>
            </div>
        </form>

        @if ($eligible === 0)
            <div class="surface px-6 py-10 text-center text-sm text-slate-600">Personne à qui écrire dans cette sélection pour le moment.</div>
        @else
            <form method="POST" action="{{ route('admin.people.bulk.send') }}" class="surface space-y-4 p-6">
                @csrf
                @foreach ($query as $key => $value) <input type="hidden" name="{{ $key }}" value="{{ $value }}"> @endforeach
                <input type="hidden" name="canal" value="{{ $channel }}"><input type="hidden" name="modele" value="{{ $template }}">

                <div>
                    <label for="subject" class="text-xs font-medium text-slate-600">{{ $channel === 'email' ? 'Objet' : 'Titre (65 caractères)' }}</label>
                    <input id="subject" name="subject" value="{{ $subject }}" maxlength="{{ $channel === 'email' ? 190 : 65 }}" class="field">
                </div>
                <div>
                    <label for="body" class="text-xs font-medium text-slate-600">Message</label>
                    <textarea id="body" name="body" rows="{{ $channel === 'email' ? 10 : 3 }}" maxlength="{{ $channel === 'email' ? 5000 : 178 }}" class="field">{{ $body }}</textarea>
                    <p class="mt-1 text-xs text-slate-500">Variables remplacées pour chacun : <code>{prenom}</code> <code>{entreprise}</code> <code>{assistant}</code> <code>{expediteur}</code> <code>{marque}</code> <code>{date_fin}</code> <code>{jours}</code>.{{ $channel === 'email' ? ' « Bonjour Prénom, » est ajouté tout seul.' : '' }}</p>
                </div>

                @if ($preview)
                    <div class="rounded-xl bg-slate-50 p-4 text-sm">
                        <p class="text-xs font-medium text-slate-500">Aperçu pour {{ $preview['who'] }}</p>
                        @if ($channel === 'email' && $preview['subject']) <p class="mt-1 font-medium text-slate-900">{{ $preview['subject'] }}</p> @endif
                        <p class="mt-1 whitespace-pre-line break-words text-slate-700">{{ $preview['body'] }}</p>
                    </div>
                @endif

                <label class="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" name="reviewed" value="1" required class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"> J'ai relu le message et l'aperçu.</label>

                <div class="flex flex-wrap items-center gap-3">
                    <button class="btn-primary" @disabled($thisBatch < 1) onclick="return confirm('Envoyer ce message à {{ $thisBatch }} personne(s) ?')">Envoyer à {{ $thisBatch }} personne(s)</button>
                    <span class="text-xs text-slate-500">Sur {{ $eligible }} possibles.</span>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
