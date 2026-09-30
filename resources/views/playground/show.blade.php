<x-bot-layout :bot="$bot" tab="playground">
    <div class="grid gap-6 lg:grid-cols-3"
         x-data="playground({ url: '{{ route('playground.ask', $bot) }}', welcome: @js($bot->welcome()), history: @js($history->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()) })">

        <div class="lg:col-span-2 surface flex flex-col h-[70vh]">
            <div class="flex items-center justify-between px-5 py-3 border-b border-slate-100">
                <div class="text-sm text-slate-600">Discutez avec votre assistant comme le ferait un client.</div>
                <form method="POST" action="{{ route('playground.reset', $bot) }}">@csrf
                    <button class="text-sm text-slate-500 hover:text-slate-800">Nouvelle conversation</button>
                </form>
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-4 space-y-3" x-ref="scroller">
                <div class="max-w-[80%] rounded-2xl rounded-bl-sm bg-slate-100 px-4 py-2 text-sm text-slate-800" x-text="welcome"></div>
                <template x-for="(m, i) in messages" :key="i">
                    <div :class="m.role === 'user' ? 'flex justify-end' : 'flex'">
                        <div :class="m.role === 'user' ? 'bg-brand-600 text-white rounded-br-sm' : 'bg-slate-100 text-slate-800 rounded-bl-sm'"
                             class="max-w-[80%] rounded-2xl px-4 py-2 text-sm whitespace-pre-wrap" x-text="m.content"></div>
                    </div>
                </template>
                <div x-show="loading" class="text-sm text-slate-400">L'assistant réfléchit…</div>
            </div>

            <form @submit.prevent="send" class="border-t border-slate-100 p-3 flex gap-2">
                <input x-model="draft" :disabled="loading" placeholder="Posez une question…" maxlength="2000"
                       class="flex-1 rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                <button :disabled="loading || !draft.trim()" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-500 disabled:opacity-50">Envoyer</button>
            </form>
        </div>

        <aside class="surface p-5 space-y-4 h-fit">
            <h3 class="font-medium text-slate-900">Comment l'assistant a répondu</h3>
            <template x-if="!last"><p class="text-sm text-slate-500">Envoyez un message : les extraits utilisés pour répondre apparaîtront ici.</p></template>
            <template x-if="last">
                <div class="space-y-3 text-sm">
                    <div>
                        <span x-show="last.grounded === true" class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Réponse appuyée sur vos sources</span>
                        <span x-show="last.grounded === false" class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">Sans réponse dans vos sources</span>
                    </div>
                    <div class="text-slate-500">
                        Meilleur score : <span x-text="last.top_score ?? '0'"></span>
                        <template x-if="last.latency_ms"> · <span x-text="(last.latency_ms/1000).toFixed(1)"></span> s</template>
                    </div>
                    <p x-show="last.grounded === false" class="text-slate-600">Ajoutez l'information manquante dans « Connaissances » ou depuis l'onglet « Analytique ».</p>
                    <ul class="space-y-2">
                        <template x-for="s in last.sources" :key="s.chunk_id">
                            <li class="rounded-md border border-slate-200 px-3 py-2">
                                <div class="font-medium text-slate-800" x-text="s.title || 'Source'"></div>
                                <div class="text-xs text-slate-500" x-text="(s.heading || '') + ' · score ' + s.score"></div>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>
        </aside>
    </div>

    <script>
        function playground({ url, welcome, history }) {
            return {
                welcome, messages: history, draft: '', loading: false, last: null,
                async send() {
                    const text = this.draft.trim();
                    if (!text) return;
                    this.messages.push({ role: 'user', content: text });
                    this.draft = ''; this.loading = true; this.scroll();
                    try {
                        const res = await fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                                       'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ message: text }),
                        });
                        const data = await res.json();
                        if (!res.ok) throw new Error(data.message || 'Erreur ' + res.status);
                        if (data.reply) this.messages.push({ role: 'assistant', content: data.reply });
                        this.last = data;
                    } catch (e) {
                        this.messages.push({ role: 'assistant', content: 'Erreur : ' + e.message });
                    } finally { this.loading = false; this.scroll(); }
                },
                scroll() { this.$nextTick(() => { this.$refs.scroller.scrollTop = this.$refs.scroller.scrollHeight; }); },
            };
        }
    </script>
</x-bot-layout>
