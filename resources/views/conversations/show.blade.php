<x-bot-layout :bot="$bot" tab="conversations">
    @php
        $closed = $conversation->status === 'closed';
        $isWhatsApp = $conversation->channel === 'whatsapp';
        $whatsappClosed = $isWhatsApp && ! $conversation->isWithinServiceWindow();
        $firstName = $conversation->firstName();
        // Le prénom ne remplit la première variable que si le modèle la nomme ainsi (modèles de la bibliothèque).
        $templatePayload = $templates->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'body' => $t->body, 'vars' => (int) $t->variables_count,
            'labels' => $t->variableLabels(),
            'prefill' => $firstName && preg_match('/pr[ée]nom|first name/iu', $t->variableLabels()[0] ?? '') ? [$firstName] : [],
        ])->values();
        $showTemplates = $whatsappClosed || $preselected;
    @endphp

    <div class="mb-4">
        <a href="{{ route('conversations.index', $bot) }}" class="text-sm text-slate-500 hover:text-brand-700">Toutes les conversations</a>
    </div>

    @foreach ($conversation->openLeads as $lead)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl rounded-bl-md border border-accent-300 bg-accent-50 px-5 py-3 text-sm">
            <p><x-badge tone="amber">{{ $lead->label() }}</x-badge> <strong class="ms-1">{{ $lead->title }}</strong>@if ($lead->summary && $lead->summary !== $lead->title) <span class="text-slate-600">: {{ $lead->summary }}</span>@endif</p>
            <a href="{{ route('leads.index') }}" class="font-medium text-brand-700 underline">Voir les demandes</a>
        </div>
    @endforeach

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="surface flex flex-col lg:col-span-2">
            <div class="max-h-[60vh] flex-1 space-y-3 overflow-y-auto px-5 py-4">
                @foreach ($messages as $message)
                    @php
                        $isUser = $message->role === 'user';
                        $bubble = $isUser ? 'bg-slate-100 text-slate-800 rounded-bl-sm' : ($message->role === 'agent' ? 'bg-emerald-600 text-white rounded-br-sm' : 'bg-brand-600 text-white rounded-br-sm');
                    @endphp
                    <div class="flex {{ $isUser ? '' : 'justify-end' }}">
                        <div class="max-w-[80%]">
                            @php $image = $isUser ? ($message->meta['image'] ?? null) : null; @endphp
                            @if ($image)
                                @if ($url = $message->imageUrl())
                                    <a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="Photo envoyée par le client" loading="lazy" class="mb-1 max-h-60 rounded-xl border border-slate-200"></a>
                                @elseif (! empty($image['expired']))
                                    <p class="mb-1 text-xs italic text-slate-500">Photo effacée (conservée {{ config('platform.vision.retention_days') }} jours).</p>
                                @elseif (! empty($image['sensitive']))
                                    <p class="mb-1 text-xs italic text-amber-700">Document confidentiel : la photo n'a pas été conservée.</p>
                                @endif
                                @if (! empty($image['summary']))
                                    <p class="mb-1 text-xs text-slate-600">Lu sur la photo ({{ $image['category'] ?? 'autre' }}) : {{ $image['summary'] }}@if (! empty($image['details'])) <span class="block">{{ $image['details'] }}</span>@endif</p>
                                @endif
                            @endif
                            @unless ($image && $message->content === '[Photo]')
                                <div class="whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm {{ $bubble }}">{{ $message->content }}</div>
                            @endunless
                            <div class="mt-1 text-[11px] text-slate-500 {{ $isUser ? '' : 'text-right' }}">
                                {{ $isUser ? 'Client' : ($message->role === 'agent' ? 'Conseiller'.(($message->meta['agent'] ?? null) ? ' : '.$message->meta['agent'] : '') : 'Assistant'.(($message->meta['provider'] ?? null) ? ' ('.$message->meta['provider'].')' : '')) }}
                                , {{ $message->created_at->format('d/m H:i') }}
                                @if ($message->isUngrounded()) <span class="text-accent-700">, sans réponse dans les sources</span> @endif
                                @if ($message->meta['delivery_error'] ?? null) <span class="text-red-600">, non livré</span> @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @unless ($closed)
                @if ($showTemplates)
                    <div class="border-t border-slate-100 p-4" x-data="{ id: '{{ $preselected?->id }}', templates: @js($templatePayload), get t() { return this.templates.find(x => String(x.id) === String(this.id)); } }">
                        @if ($whatsappClosed)
                            <p class="mb-3 rounded-lg bg-accent-50 px-3 py-2 text-xs text-accent-800">
                                Le client n'a pas écrit depuis plus de 24 h : WhatsApp n'autorise plus de message libre. Envoyez un modèle approuvé pour reprendre la conversation.
                            </p>
                        @else
                            <p class="mb-3 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-800">
                                Le modèle « {{ config('whatsapp_templates.templates.'.$preselected->name.'.title', $preselected->name) }} » est prêt : complétez les informations puis envoyez. Vous pouvez aussi écrire librement plus bas.
                            </p>
                        @endif
                        @if ($templates->isEmpty())
                            <p class="text-sm text-slate-600">
                                Aucun modèle approuvé disponible.
                                <a class="font-medium text-brand-600 underline" href="{{ route('templates.index', $bot) }}">Gérer les modèles</a>
                            </p>
                        @else
                            <form method="POST" action="{{ route('conversations.template', [$bot, $conversation]) }}" class="space-y-3">
                                @csrf
                                <select name="template_id" x-model="id" required class="field">
                                    <option value="">Choisir un modèle…</option>
                                    @foreach ($templates as $template)
                                        <option value="{{ $template->id }}">{{ $template->name }} ({{ strtoupper($template->language) }})</option>
                                    @endforeach
                                </select>
                                <template x-if="t">
                                    <div class="space-y-2">
                                        <p class="whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700" x-text="t.body"></p>
                                        <template x-for="i in t.vars" :key="t.id + '-' + i">
                                            <label class="block text-xs font-medium text-slate-600">
                                                <span x-text="t.labels[i - 1] || ('Valeur ' + i)"></span>
                                                <input :name="'variables[' + (i - 1) + ']'" required class="field" :value="t.prefill[i - 1] || ''">
                                            </label>
                                        </template>
                                    </div>
                                </template>
                                <button class="btn-primary" :disabled="! t">Envoyer le modèle</button>
                            </form>
                        @endif
                    </div>
                @endif
                @unless ($whatsappClosed)
                    <form method="POST" action="{{ route('conversations.reply', [$bot, $conversation]) }}" class="border-t border-slate-100 p-3">
                        @csrf
                        <div class="flex gap-2">
                            <input name="content" required maxlength="2000" placeholder="Répondre en tant que conseiller…" class="field !mt-0 flex-1">
                            <button class="btn-primary">Envoyer</button>
                        </div>
                    </form>
                @endunless
            @endunless
        </div>

        <aside class="space-y-4">
            <div class="surface space-y-2 p-5 text-sm">
                <h3 class="font-display font-bold text-brand-950">Client</h3>
                <div class="text-slate-800">{{ $conversation->displayName() }}</div>
                @if ($conversation->contact_phone) <div class="text-slate-600">{{ $conversation->contact_phone }}</div> @endif
                @if ($conversation->contact_email) <div class="text-slate-600">{{ $conversation->contact_email }}</div> @endif
                <div class="text-slate-600">Canal : {{ $isWhatsApp ? 'WhatsApp' : 'Site web' }}</div>
                @if ($conversation->meta['handoff_reason'] ?? null)
                    <div class="text-accent-700">Transfert demandé : {{ $conversation->meta['handoff_reason'] }}</div>
                @endif
            </div>

            <div class="surface space-y-3 p-5">
                <h3 class="font-display font-bold text-brand-950">Actions</h3>
                @foreach ([
                    ['take', 'Prendre la main', 'L\'assistant cesse de répondre.', in_array($conversation->status, ['bot', 'needs_human'])],
                    ['release', 'Rendre à l\'assistant', 'L\'assistant reprend la discussion.', in_array($conversation->status, ['human', 'needs_human'])],
                    ['close', 'Clôturer', 'Conversation terminée.', ! $closed],
                ] as [$action, $label, $hint, $show])
                    @if ($show)
                        <form method="POST" action="{{ route('conversations.status', [$bot, $conversation]) }}">
                            @csrf <input type="hidden" name="action" value="{{ $action }}">
                            <button class="w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-50">
                                <span class="font-medium text-brand-950">{{ $label }}</span>
                                <span class="block text-xs text-slate-600">{{ $hint }}</span>
                            </button>
                        </form>
                    @endif
                @endforeach
            </div>
        </aside>
    </div>
</x-bot-layout>
