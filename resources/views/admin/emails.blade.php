<x-app-layout title="E-mails | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="E-mails de la plateforme" subtitle="Tous les messages envoyés automatiquement, avec des données d'exemple. Regardez-les, puis envoyez-vous un essai pour les voir dans une vraie boîte de réception.">
            <x-slot name="actions">
                <form method="POST" action="{{ route('admin.emails.send', $current) }}">@csrf
                    <button class="btn-primary"><x-icon name="inbox" class="h-4 w-4" /> M'envoyer cet e-mail</button>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if (in_array($driver, ['log', 'array']))
            <div class="rounded-xl border border-accent-300 bg-accent-50 px-5 py-4 text-sm">
                <strong>L'envoi réel est désactivé</strong> (pilote « {{ $driver }} » : les messages sont écrits dans les journaux, rien ne part). En production, définissez <code>MAIL_MAILER=smtp</code> et les identifiants de la boîte dans <code>.env</code>, puis lancez <code>php artisan platform:mail-test</code>.
            </div>
        @else
            <p class="text-sm text-slate-600">Envoi par « {{ $driver }} », depuis <strong>{{ $from }}</strong>. Si un essai n'arrive pas, regardez les courriers indésirables, puis la procédure du guide du super admin (SPF, DKIM, mot de passe de la boîte).</p>
        @endif

        {{-- Délivrabilité : pourquoi les e-mails peuvent finir dans les courriers indésirables --}}
        <details class="surface" @if ($healthSummary['level'] !== 'ok') open @endif>
            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3 px-6 py-4">
                <span class="font-display text-lg font-bold text-brand-950">Arrivent-ils bien dans la boîte de réception ?</span>
                <span class="flex items-center gap-2">
                    <x-badge :tone="['ok' => 'green', 'warn' => 'amber', 'bad' => 'red'][$healthSummary['level']]">{{ $healthSummary['label'] }} ({{ $healthSummary['score'] }}/100)</x-badge>
                </span>
            </summary>
            <div class="border-t border-slate-100 px-6 py-4">
                <p class="text-sm text-slate-600">La plateforme lit la zone DNS de <strong>{{ (new \App\Support\MailHealth)->domain() ?? 'votre domaine' }}</strong> : SPF, DKIM et DMARC sont les trois preuves que Gmail et Outlook demandent pour ne pas classer vos messages en indésirables. Une correction DNS met de quelques minutes à quelques heures à se voir.</p>
                <ul class="mt-4 space-y-3">
                    @foreach ($health as $check)
                        <li class="flex items-start gap-3 text-sm">
                            <x-badge :tone="['ok' => 'green', 'warn' => 'amber', 'bad' => 'red', 'info' => 'gray'][$check['status']]" class="mt-0.5 shrink-0">{{ ['ok' => 'OK', 'warn' => 'À voir', 'bad' => 'À corriger', 'info' => 'Info'][$check['status']] }}</x-badge>
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">{{ $check['label'] }}</p>
                                <p class="break-words text-slate-600">{{ $check['detail'] }}</p>
                                @if ($check['fix'] && $check['status'] !== 'ok') <p class="mt-0.5 text-slate-700"><strong>Que faire :</strong> {{ $check['fix'] }}</p> @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <a href="{{ route('admin.emails.index', ['modele' => $current, 'verifier' => 1]) }}" class="btn-outline">Vérifier à nouveau</a>
                    <a href="{{ route('staff.guide', 'super-admin') }}#13-les-e-mails-et-les-notifications" class="text-sm font-medium text-brand-700 underline">Pas à pas dans le guide</a>
                </div>
            </div>
        </details>
        <div class="grid gap-6 lg:grid-cols-3">
            <nav class="space-y-5 lg:col-span-1" aria-label="Modèles d'e-mails">
                @foreach ($groups as $groupKey => $groupLabel)
                    <section class="surface">
                        <h2 class="border-b border-slate-100 px-5 py-3 font-display text-sm font-bold text-brand-950">{{ $groupLabel }}</h2>
                        <ul class="p-2">
                            @foreach ($catalog as $key => $item)
                                @if ($item['group'] === $groupKey)
                                    <li>
                                        <a href="{{ route('admin.emails.index', ['modele' => $key]) }}" @class(['block rounded-xl px-3 py-2 text-sm transition', 'bg-brand-600 text-white' => $current === $key, 'text-slate-700 hover:bg-brand-50' => $current !== $key])>
                                            <span class="block font-medium">{{ $item['label'] }}</span>
                                            <span class="block text-xs {{ $current === $key ? 'text-white/75' : 'text-slate-500' }}">{{ $item['note'] }}</span>
                                        </a>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </nav>

            <section class="surface overflow-hidden lg:col-span-2">
                <div class="border-b border-slate-100 px-5 py-3 text-sm">
                    <span class="text-slate-500">Objet :</span> <strong class="text-brand-950">{{ $subject }}</strong>
                </div>
                <iframe src="{{ route('admin.emails.show', $current) }}" title="Aperçu de l'e-mail" class="h-[44rem] w-full bg-[#F1F4FB]" sandbox></iframe>
            </section>
        </div>
    </div>
</x-app-layout>
