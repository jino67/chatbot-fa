<x-app-layout>
    @php
        $input = 'mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm';
        $provider = old('provider', $channel?->type ?? 'whatsapp_meta');
        $cred = fn ($key) => old($key, '');
    @endphp

    <x-slot name="header">
        <a href="{{ route('admin.requests.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Toutes les demandes</a>
        <h2 class="mt-1 font-semibold text-xl text-slate-800 leading-tight">{{ $req->business_name }} <span class="text-slate-400 font-normal">· {{ $req->phone_number }}</span></h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 grid gap-6 lg:grid-cols-3">

            {{-- Demande --}}
            <aside class="surface p-6 space-y-3 text-sm h-fit">
                <h3 class="font-medium text-slate-900">Demande du client</h3>
                <dl class="space-y-2 text-slate-600">
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Client</dt><dd>{{ $req->workspace->name }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Assistant</dt><dd>{{ $req->bot?->name }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Demandeur</dt><dd>{{ $req->requester?->name }} ({{ $req->requester?->email }})</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Pays</dt><dd>{{ $req->country ?: 'non précisé' }}</dd></div>
                    @if ($req->notes) <div><dt class="text-xs uppercase tracking-wide text-slate-400">Précisions</dt><dd class="whitespace-pre-line">{{ $req->notes }}</dd></div> @endif
                </dl>

                <div class="border-t border-slate-100 pt-4 space-y-2">
                    <h4 class="font-medium text-slate-900">Choisir le fournisseur</h4>
                    <p class="text-slate-600"><strong>Meta Cloud API</strong> par défaut : aucune marge par message, un seul webhook. <strong>Twilio</strong> si le client a besoin d'un démarrage immédiat ou si son numéro doit être fourni. Détails : <code>docs/WHATSAPP.md</code>.</p>
                </div>
            </aside>

            {{-- Configuration du canal --}}
            <div class="lg:col-span-2 space-y-6">
                <form method="POST" action="{{ route('admin.requests.update', $req->id) }}" class="surface p-6 space-y-5" x-data="{ provider: '{{ $provider }}' }">
                    @csrf @method('PUT')
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium text-slate-900">Configuration du canal</h3>
                        @if ($channel) <x-badge :tone="$channel->status === 'active' ? 'green' : 'amber'">{{ $channel->status }}</x-badge> @endif
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="provider" value="Fournisseur" />
                            <select id="provider" name="provider" x-model="provider" class="{{ $input }}">
                                <option value="whatsapp_meta">Meta Cloud API</option>
                                <option value="whatsapp_twilio">Twilio</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="display_phone" value="Numéro affiché" />
                            <input id="display_phone" name="display_phone" class="{{ $input }}" value="{{ old('display_phone', $channel?->display_phone ?? $req->phone_number) }}">
                        </div>
                        <div>
                            <x-input-label for="status" value="État du canal" />
                            <select id="status" name="status" class="{{ $input }}">
                                @foreach (['pending' => 'En configuration', 'active' => 'Actif', 'disabled' => 'Désactivé'] as $k => $label)
                                    <option value="{{ $k }}" @selected(old('status', $channel?->status ?? 'pending') === $k)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Meta --}}
                    <div x-show="provider === 'whatsapp_meta'" class="space-y-4 rounded-lg bg-slate-50 p-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="phone_number_id" value="phone_number_id" />
                                <input id="phone_number_id" name="phone_number_id" class="{{ $input }}" value="{{ old('phone_number_id', $channel?->type === 'whatsapp_meta' ? $channel->external_ref : '') }}" inputmode="numeric">
                            </div>
                            <div>
                                <x-input-label for="waba_id" value="WABA ID (facultatif)" />
                                <input id="waba_id" name="waba_id" class="{{ $input }}" value="{{ old('waba_id', $channel?->type === 'whatsapp_meta' ? $channel->credential('waba_id') : '') }}">
                            </div>
                        </div>
                        <div>
                            <x-input-label for="access_token" value="Jeton d'accès (utilisateur système)" />
                            <input id="access_token" name="access_token" type="password" autocomplete="off" class="{{ $input }}" placeholder="{{ $channel?->type === 'whatsapp_meta' && $channel->credential('access_token') ? '•••••••• enregistré, laisser vide pour le conserver' : '' }}">
                        </div>
                        <div class="text-xs text-slate-600 space-y-1">
                            <div>URL du webhook à saisir chez Meta : <code class="break-all">{{ $metaWebhook }}</code></div>
                            <div>Jeton de vérification : @if ($verifyToken) <code>{{ $verifyToken }}</code> @else <span class="text-red-600">non défini (META_VERIFY_TOKEN dans .env)</span> @endif</div>
                            <div>Champ à abonner : <code>messages</code></div>
                        </div>
                    </div>

                    {{-- Twilio --}}
                    <div x-show="provider === 'whatsapp_twilio'" x-cloak class="space-y-4 rounded-lg bg-slate-50 p-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="account_sid" value="Account SID" />
                                <input id="account_sid" name="account_sid" class="{{ $input }}" value="{{ old('account_sid', $channel?->type === 'whatsapp_twilio' ? $channel->credential('account_sid') : '') }}">
                            </div>
                            <div>
                                <x-input-label for="auth_token" value="Auth Token" />
                                <input id="auth_token" name="auth_token" type="password" autocomplete="off" class="{{ $input }}" placeholder="{{ $channel?->type === 'whatsapp_twilio' && $channel->credential('auth_token') ? '•••••••• enregistré, laisser vide pour le conserver' : '' }}">
                            </div>
                            <div>
                                <x-input-label for="from" value="Numéro expéditeur WhatsApp" />
                                <input id="from" name="from" class="{{ $input }}" placeholder="+14155238886" value="{{ old('from', $channel?->type === 'whatsapp_twilio' ? $channel->credential('from') : '') }}">
                            </div>
                            <div>
                                <x-input-label for="messaging_service_sid" value="Messaging Service SID (facultatif)" />
                                <input id="messaging_service_sid" name="messaging_service_sid" class="{{ $input }}" value="{{ old('messaging_service_sid', $channel?->type === 'whatsapp_twilio' ? $channel->credential('messaging_service_sid') : '') }}">
                            </div>
                        </div>
                        <div class="text-xs text-slate-600">
                            @if ($channel?->type === 'whatsapp_twilio')
                                URL à saisir dans « When a message comes in » (POST) : <code class="break-all">{{ url('/webhooks/whatsapp/twilio/'.$channel->id) }}</code>
                            @else
                                L'URL du webhook Twilio sera affichée après le premier enregistrement.
                            @endif
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="request_status" value="Statut de la demande" />
                            <select id="request_status" name="request_status" class="{{ $input }}">
                                @foreach (['requested' => 'Reçue', 'in_progress' => 'En cours', 'active' => 'Activée', 'rejected' => 'Refusée'] as $k => $label)
                                    <option value="{{ $k }}" @selected(old('request_status', $req->status) === $k)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="admin_notes" value="Message visible par le client" />
                            <input id="admin_notes" name="admin_notes" class="{{ $input }}" value="{{ old('admin_notes', $req->admin_notes) }}">
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <x-primary-button>Enregistrer</x-primary-button>
                    </div>
                </form>

                @if ($channel)
                    <form method="POST" action="{{ route('admin.channels.test', $channel->id) }}" class="surface p-6 flex items-center justify-between gap-4">
                        @csrf
                        <div>
                            <h3 class="font-medium text-slate-900">Tester la connexion</h3>
                            <p class="text-sm text-slate-500">Vérifie le jeton auprès de {{ $channel->providerLabel() }} avant d'activer le canal.</p>
                        </div>
                        <button class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">Tester</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
