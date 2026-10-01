/*
 * Démonstration interactive de l'appareil pliable (components/fold-demo.blade.php). Exemple fictif, mais tout y
 * fonctionne : le visiteur écrit ou dicte au nom du client, l'assistant répond (dialogue local, voir nlu.js), les
 * demandes arrivent chez la gérante qui choisit comment être prévenue, répond, confirme une commande ou rend la main
 * à l'assistant. L'appareil se plie et se déplie (bouton, charnière, glissement, notification de couverture).
 * Une histoire se joue d'abord toute seule ; au premier geste du visiteur elle s'arrête et il prend la main.
 */
import { answer, createState, resumeText, STORY, SHOP } from './nlu.js';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/** Texte de l'utilisateur ou de l'assistant : échappé, puis **gras** et retours à la ligne. */
const rich = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');

const CUSTOMER = { name: 'Fatou', phone: '+226 70 12 34 56' };

const ICON = {
    whatsapp: '<svg viewBox="0 0 20 20" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 16.5 4.6 13A6.5 6.5 0 1 1 7 15.4Z"/></svg>',
    email: '<svg viewBox="0 0 20 20" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="14" height="10" rx="2"/><path d="m3.5 6.5 6.5 5 6.5-5"/></svg>',
    bell: '<svg viewBox="0 0 20 20" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5.5 13.5V9a4.5 4.5 0 0 1 9 0v4.5l1.2 1.5H4.3Z"/><path d="M8.5 17h3"/></svg>',
};

const BADGE = {
    order: { text: 'Commande à confirmer', cls: 'bg-accent-100 text-accent-700', dot: 'bg-accent-500', ring: 'ring-accent-400/60' },
    human: { text: 'Demande une personne', cls: 'bg-hibiscus-500/10 text-hibiscus-600', dot: 'bg-hibiscus-500 animate-pulse', ring: 'ring-hibiscus-500/40' },
    taken: { text: 'Prise en charge', cls: 'bg-brand-100 text-brand-700', dot: 'bg-brand-500', ring: 'ring-brand-300/60' },
    confirmed: { text: 'Confirmée', cls: 'bg-feuille-500/10 text-feuille-600', dot: 'bg-feuille-500', ring: 'ring-feuille-500/40' },
    resumed: { text: 'Rendue à l\'assistant', cls: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400', ring: 'ring-slate-200' },
};

const VOICE_SAMPLES = ['Bonsoir, vous livrez à Bobo ?', 'Combien coûte un boubou brodé ?', 'Vous êtes ouverts le dimanche ?', 'Je voudrais deux foulards'];

export function initPlayground(root, { animate = true } = {}) {
    const q = (selector) => root.querySelector(selector);
    const device = q('[data-fold]');
    const body = q('[data-pg-body]');
    const typing = q('[data-pg-typing]');
    if (!device || !body || !typing) return;

    const chipsBox = q('[data-pg-chips]');
    const form = q('[data-pg-form]');
    const input = q('[data-pg-input]');
    const micBtn = q('[data-pg-mic]');
    const speaker = q('[data-pg-speaker]');
    const status = q('[data-pg-status]');
    const leadsBox = q('[data-pg-leads]');
    const emptyBox = q('[data-pg-empty]');
    const countEl = q('[data-pg-count]');
    const gear = q('[data-pg-gear]');
    const settings = q('[data-pg-settings]');
    const toasts = q('[data-pg-toasts]');
    const ownerForm = q('[data-pg-owner]');
    const ownerInput = q('[data-pg-owner-input]');
    const ownerSend = q('[data-pg-owner-send]');
    const coverBtn = q('[data-pg-cover]');
    const coverNotes = q('[data-pg-cover-notes]');
    const coverHint = q('[data-pg-cover-hint]');
    const clock = q('[data-pg-clock]');
    const dateEl = q('[data-pg-date]');
    const hinge = q('[data-pg-hinge]');
    const controls = q('[data-pg-controls]');
    const toggleBtn = q('[data-pg-toggle]');
    const replayBtn = q('[data-pg-replay]');
    const resetBtn = q('[data-pg-reset]');
    const hint = q('[data-pg-hint]');
    const leftHalf = q('.fold-left');
    const front = q('.fold-front');
    const back = q('.fold-back');

    let nlu = createState();
    let leads = [];
    let run = 0; // jeton de l'histoire : le changer l'arrête
    let turn = 0; // jeton de la conversation : le changer abandonne les réponses en cours
    let chain = Promise.resolve();
    let storyOn = false;
    let live = animate; // faux : le contenu statique (histoire terminée) est encore à l'écran
    let seq = 0;
    let voiceIndex = 0;
    let told = false;
    const notify = { whatsapp: true, email: true, dashboard: true };

    const folded = () => device.dataset.state === 'closed';
    const scrollDown = () => body.scrollTo({ top: body.scrollHeight, behavior: animate ? 'smooth' : 'auto' });
    const drop = (el) => {
        const box = el.getBoundingClientRect();
        window.dispatchEvent(new CustomEvent('kouma:drop', { detail: { x: box.left + box.width / 2, y: box.top + box.height / 2 } }));
    };

    /* ------------------------------------------------------------------ conversation du client */

    function bubble(kind, html, { source, audio } = {}) {
        const el = document.createElement('div');
        const classes = {
            user: 'demo-user ms-auto max-w-[84%] rounded-2xl rounded-br-sm bg-brand-100 px-3 py-1.5',
            bot: 'demo-bot max-w-[90%] rounded-2xl rounded-bl-sm bg-white px-3 py-1.5 shadow-sm',
            agent: 'demo-bot max-w-[90%] rounded-2xl rounded-bl-sm bg-accent-50 px-3 py-1.5 shadow-sm ring-1 ring-accent-300',
            system: 'mx-auto max-w-[92%] rounded-full bg-slate-200/70 px-3 py-1 text-center text-[10.5px] text-slate-600',
        };
        el.className = `${classes[kind]} demo-pop`;
        el.innerHTML = html
            + (source ? `<span class="mt-1.5 block"><span class="inline-block rounded-full bg-slate-100 px-2 py-0.5 text-[10.5px] text-slate-600">Source : ${esc(source)}</span></span>` : '')
            + (audio ? `<button type="button" data-listen class="pg-act mt-1.5 flex items-center gap-1.5 bg-brand-50 text-brand-700 ring-1 ring-brand-200"><svg viewBox="0 0 20 20" class="h-3 w-3" fill="currentColor" aria-hidden="true"><path d="M6 4.5v11l9-5.5Z"/></svg><span>Écouter la réponse</span><span class="text-brand-500">0:${String(Math.max(3, Math.round(audio.length / 14))).padStart(2, '0')}</span></button>` : '');
        body.insertBefore(el, typing);
        el.querySelector('[data-listen]')?.addEventListener('click', (event) => speak(audio, event.currentTarget));
        scrollDown();

        return el;
    }

    /** Lecture à voix haute par la synthèse vocale de l'appareil du visiteur (si elle existe). */
    function speak(text, button) {
        if (!('speechSynthesis' in window)) {
            button.remove();

            return;
        }
        const label = button.querySelector('span');
        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text.replace(/\*\*/g, '').replace(/FCFA/g, 'francs CFA').replace(/\n/g, '. '));
        utterance.lang = nlu.lang === 'en' ? 'en-US' : 'fr-FR';
        utterance.onstart = () => { label.textContent = 'Lecture…'; };
        utterance.onend = () => { label.textContent = 'Écouter la réponse'; };
        window.speechSynthesis.speak(utterance);
    }

    async function think(ms) {
        body.appendChild(typing);
        typing.hidden = false;
        speaker.classList.add('is-speaking');
        status.textContent = 'écrit…';
        scrollDown();
        await sleep(animate ? ms : 0);
        typing.hidden = true;
        speaker.classList.remove('is-speaking');
        status.textContent = nlu.handoff ? `${SHOP.owner} a été prévenue` : 'assistant en ligne';
    }

    function setChips(list = []) {
        chipsBox.replaceChildren(...list.slice(0, 4).map((label) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pg-chip';
            button.textContent = label;
            button.addEventListener('click', () => send(label));

            return button;
        }));
        chipsBox.scrollLeft = 0;
    }

    function voiceBubbleHtml(seconds) {
        const bars = Array.from({ length: 16 }, (_, i) => `<i style="height:${30 + Math.round(Math.abs(Math.sin(i * 1.7 + seconds)) * 70)}%"></i>`).join('');

        return `<span class="flex items-center gap-2 text-brand-700"><span class="pg-wave is-live">${bars}</span><span class="text-[11px] text-slate-600">0:${String(seconds).padStart(2, '0')}</span></span><span data-transcript class="mt-1 block text-[11px] italic text-slate-600">Transcription…</span>`;
    }

    /** Envoie un message au nom du client (écrit, réponse rapide ou vocal) ; la réponse suit dans la file. */
    function send(text, { voice = false } = {}) {
        text = String(text).trim();
        if (!text) return chain;

        ensureLive();
        const mine = turn;
        setChips([]);
        input.value = '';

        const seconds = Math.max(2, Math.round(text.length / 9));
        const el = bubble('user', voice ? voiceBubbleHtml(seconds) : `${rich(text)}<span class="pg-ticks" aria-hidden="true">✓</span>`);
        if (!voice) el.dataset.plain = '1';

        chain = chain.then(() => respond(text, el, mine, voice)).catch(() => {});

        return chain;
    }

    async function respond(text, el, mine, voice) {
        if (voice) {
            await sleep(animate ? 900 : 0);
            const transcript = el.querySelector('[data-transcript]');
            if (transcript) transcript.textContent = `« ${text} »`;
            el.querySelector('.pg-wave')?.classList.remove('is-live');
            await sleep(animate ? 350 : 0);
        } else {
            await sleep(animate ? 260 : 0);
            const ticks = el.querySelector('.pg-ticks');
            if (ticks) ticks.textContent = '✓✓';
            await sleep(animate ? 260 : 0);
        }
        if (mine !== turn) return;

        const result = answer(nlu, text);

        if (result.silent) {
            ownerHears(text);

            return;
        }

        el.querySelector('.pg-ticks')?.classList.add('is-read');

        for (const reply of result.replies) {
            await think(Math.min(1700, 520 + reply.text.length * 9));
            if (mine !== turn) return;
            const message = bubble('bot', rich(reply.text), { source: reply.source, audio: voice ? reply.text : null });
            drop(message);
        }

        const last = result.replies[result.replies.length - 1];
        if (last?.chips) setChips(last.chips);

        if (result.leads.length) {
            await sleep(animate ? 500 : 0);
            if (mine !== turn) return;
            result.leads.forEach((lead) => addLead(lead));
        }
    }

    /** Le client écrit alors qu'une personne a pris la main : l'assistant se tait, la gérante est prévenue. */
    function ownerHears(text) {
        if (!told) {
            told = true;
            bubble('system', `Votre message est transmis à ${esc(SHOP.owner)}.`);
        }

        const lead = leads.find((l) => l.kind === 'human' && l.status !== 'resumed') ?? leads[0];
        if (lead) {
            lead.el.querySelector('[data-detail]').textContent = `Dernier message : « ${text} »`;
            lead.el.classList.remove('pg-flash');
            void lead.el.offsetWidth;
            lead.el.classList.add('pg-flash');
        }

        if (notify.whatsapp) {
            toast({ icon: ICON.whatsapp, tone: 'bg-feuille-500', title: `${CUSTOMER.name} a écrit`, text });
            if (folded()) coverNote({ icon: ICON.whatsapp, tone: 'bg-feuille-500', title: 'WhatsApp', text: `${CUSTOMER.name} : « ${text} »` });
        }
    }

    /* ------------------------------------------------------------------ côté commerçant */

    const whenLabel = (ms) => {
        const minutes = Math.floor((Date.now() - ms) / 60000);

        return minutes < 1 ? 'à l\'instant' : `il y a ${minutes} min`;
    };

    function badgeHtml(key) {
        const b = BADGE[key];

        return `<span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-semibold ${b.cls}"><span class="h-1.5 w-1.5 rounded-full ${b.dot}"></span>${b.text}</span>`;
    }

    function actionButtons(lead) {
        const primary = 'pg-act bg-brand-600 text-white hover:bg-brand-700';
        const soft = 'pg-act bg-slate-100 text-slate-700 hover:bg-slate-200';
        const buttons = [];

        if (lead.status === 'new' || lead.status === 'taken') {
            if (lead.kind === 'order' && lead.status === 'new') buttons.push(['confirm', 'Confirmer la commande', primary]);
            buttons.push(['reply', 'Répondre', lead.kind === 'order' ? soft : primary]);
            if (lead.kind === 'human') buttons.push(['resume', 'Rendre à l\'assistant', soft]);
        }

        return buttons.map(([act, label, cls]) => `<button type="button" data-act="${act}" class="${cls}">${label}</button>`).join('');
    }

    function paintLead(lead) {
        const key = lead.status === 'new' ? lead.kind : lead.status;
        lead.el.className = `rounded-xl rounded-bl-sm bg-white p-3 shadow-sm ring-1 ${BADGE[key].ring}`;
        lead.el.querySelector('[data-badge]').innerHTML = badgeHtml(key);
        lead.el.querySelector('[data-actions]').innerHTML = actionButtons(lead);
    }

    function updateCount(bounce = false) {
        const pending = leads.filter((l) => l.status === 'new').length;
        countEl.textContent = String(pending);
        countEl.classList.toggle('bg-hibiscus-500', pending > 0);
        countEl.classList.toggle('bg-slate-300', pending === 0);
        countEl.hidden = !notify.dashboard;
        emptyBox.hidden = leads.length > 0;

        if (bounce && notify.dashboard && animate) {
            countEl.animate([{ transform: 'scale(1.7)' }, { transform: 'scale(1)' }], { duration: 480, easing: 'cubic-bezier(0.2, 1.4, 0.3, 1)' });
        }
    }

    function addLead(data) {
        const lead = { id: ++seq, kind: data.kind, status: 'new', at: Date.now(), el: document.createElement('div'), timer: 0, data };
        const isOrder = data.kind === 'order';

        lead.el.innerHTML = `
            <div class="flex items-center justify-between gap-1"><span data-badge></span><span data-when class="shrink-0 text-[10px] text-slate-400">à l'instant</span></div>
            <p class="mt-1.5 text-[12px] font-semibold">${isOrder ? esc(data.title) : `${CUSTOMER.name}, ${CUSTOMER.phone}`}</p>
            <p data-detail class="text-[11.5px] leading-snug text-slate-500">${isOrder ? `${esc(data.detail)} ${CUSTOMER.name}, ${CUSTOMER.phone}.` : `Écrit : « ${esc(data.detail)} »`}</p>
            <div data-actions class="mt-2 flex flex-wrap gap-1.5"></div>`;
        paintLead(lead);
        lead.el.classList.add('demo-pop');
        if (notify.dashboard) lead.el.classList.add('pg-flash');

        lead.el.addEventListener('click', (event) => {
            const act = event.target.closest('[data-act]')?.dataset.act;
            if (act === 'confirm') confirmOrder(lead);
            if (act === 'reply') focusOwner();
            if (act === 'resume') resumeAssistant(lead);
        });

        leads.unshift(lead);
        leadsBox.prepend(lead.el);
        leadsBox.scrollTo({ top: 0, behavior: 'auto' });
        updateCount(true);
        enableOwner();
        drop(lead.el);
        alertOwner(lead);

        // Une demande restée sans réponse déclenche un rappel.
        const mine = turn;
        lead.timer = setTimeout(() => {
            if (mine !== turn || lead.status !== 'new') return;
            const text = `${CUSTOMER.name} attend une réponse depuis 1 min.`;
            if (notify.whatsapp) toast({ icon: ICON.bell, tone: 'bg-accent-500 text-brand-950', title: 'Rappel', text });
            if (folded()) coverNote({ icon: ICON.bell, tone: 'bg-accent-500 text-brand-950', title: 'Rappel', text });
        }, 26000);
    }

    /** Les alertes choisies par la gérante : WhatsApp, e-mail, tableau de bord (voir les interrupteurs). */
    function alertOwner(lead) {
        const isOrder = lead.kind === 'order';
        const title = isOrder ? 'Commande à confirmer' : `${CUSTOMER.name} demande une personne`;
        const text = isOrder ? lead.data.title : `« ${lead.data.detail} »`;

        if (notify.whatsapp) {
            toast({ icon: ICON.whatsapp, tone: 'bg-feuille-500', title: `${SHOP.owner} est prévenue`, text: `Alerte WhatsApp : ${isOrder ? 'commande à confirmer' : 'demande d\'une personne'}` });
            if (folded()) coverNote({ icon: ICON.whatsapp, tone: 'bg-feuille-500', title: 'WhatsApp', text: `${title} : ${text}` });
        }

        if (notify.email) {
            toast({ icon: ICON.email, tone: 'bg-brand-500', title: 'E-mail envoyé', text: `${title}` });
            if (folded()) coverNote({ icon: ICON.email, tone: 'bg-brand-500', title: 'E-mail', text: title });
        }
    }

    function toast({ icon, tone, title, text }) {
        const el = document.createElement('div');
        el.className = 'pg-toast flex items-center gap-2 rounded-xl bg-brand-950 px-3 py-2 text-[11.5px] text-white shadow-pop';
        el.innerHTML = `<span class="grid h-6 w-6 shrink-0 place-items-center rounded-full ${tone}">${icon}</span><span class="min-w-0 leading-tight"><strong class="block truncate font-semibold">${esc(title)}</strong><span class="block truncate text-white/70">${esc(text)}</span></span>`;
        toasts.prepend(el);
        while (toasts.children.length > 2) toasts.lastElementChild.remove();

        const mine = turn;
        setTimeout(() => {
            if (mine !== turn) return;
            el.classList.add('is-out');
            setTimeout(() => el.remove(), 320);
        }, 5200);
    }

    function enableOwner() {
        ownerInput.disabled = false;
        ownerSend.disabled = false;
        ownerInput.placeholder = 'Répondre…';
    }

    function disableOwner() {
        ownerInput.value = '';
        ownerInput.disabled = true;
        ownerSend.disabled = true;
        ownerInput.placeholder = 'En attente…';
    }

    function focusOwner() {
        if (folded()) return;
        ownerInput.focus({ preventScroll: true });
    }

    function activeLead() {
        return leads.find((l) => l.status === 'new' || l.status === 'taken') ?? null;
    }

    function take(lead) {
        if (lead.status === 'new') {
            lead.status = 'taken';
            clearTimeout(lead.timer);
            paintLead(lead);
            updateCount();
        }
    }

    /** La gérante répond : son message arrive chez le client, signé. */
    async function ownerReply(text) {
        text = text.trim();
        const lead = activeLead();
        if (!text || !lead) return;

        ensureLive();
        ownerInput.value = '';
        take(lead);
        const mine = turn;

        await sleep(animate ? 500 : 0);
        if (mine !== turn) return;
        status.textContent = `${SHOP.owner} écrit…`;
        await sleep(animate ? 900 : 0);
        if (mine !== turn) return;
        status.textContent = nlu.handoff ? `${SHOP.owner} a été prévenue` : 'assistant en ligne';

        const message = bubble('agent', `<span class="mb-0.5 block text-[10.5px] font-semibold text-hibiscus-600">${esc(SHOP.owner)} · équipe</span>${rich(text)}`);
        drop(message);
    }

    async function confirmOrder(lead) {
        lead.status = 'confirmed';
        clearTimeout(lead.timer);
        paintLead(lead);
        updateCount();

        const mine = turn;
        await sleep(animate ? 600 : 0);
        if (mine !== turn) return;
        const message = bubble('agent', `<span class="mb-0.5 block text-[10.5px] font-semibold text-hibiscus-600">${esc(SHOP.owner)} · équipe</span>✅ <strong>Commande confirmée.</strong> Paiement par Orange Money, Moov Money ou à la livraison ; livraison demain avant 18 h. Merci !`);
        drop(message);
    }

    async function resumeAssistant(lead) {
        lead.status = 'resumed';
        clearTimeout(lead.timer);
        paintLead(lead);
        updateCount();
        if (!activeLead()) disableOwner();

        if (!nlu.handoff) return;
        nlu.handoff = false;
        told = false;

        bubble('system', 'L\'assistant reprend la conversation.');
        const mine = turn;
        await think(700);
        if (mine !== turn) return;
        bubble('bot', rich(resumeText(nlu.lang)));
        setChips(['Vos horaires ?', 'Le prix d\'un boubou', 'Livrez-vous à Bobo ?']);
    }

    /* ------------------------------------------------------------------ écran de couverture et pliage */

    function coverNote({ icon, tone, title, text }) {
        const el = document.createElement('span');
        el.className = 'pg-toast flex items-start gap-2.5 rounded-2xl bg-white/15 p-2.5 backdrop-blur-md';
        el.innerHTML = `<span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl ${tone}">${icon}</span><span class="min-w-0 text-[11.5px] leading-snug"><span class="block font-semibold">${esc(title)}</span><span class="block text-white/80">${esc(text)}</span></span>`;
        coverNotes.prepend(el);
        while (coverNotes.children.length > 3) coverNotes.lastElementChild.remove();
    }

    const MARK = '<svg viewBox="0 0 48 48" class="h-5 w-5" aria-hidden="true"><path d="M5 21a17 17 0 0 1 34 0Z" fill="#2340D9"/><path d="M9 27a17 17 0 0 0 34 0Z" fill="#FFB400"/></svg>';

    function refreshCover() {
        coverNotes.replaceChildren();
        const pending = leads.filter((l) => l.status === 'new').length;

        if (pending > 0) {
            coverNote({ icon: MARK, tone: 'bg-white', title: SHOP.name, text: `${pending} demande${pending > 1 ? 's' : ''} en attente : ${CUSTOMER.name} attend votre réponse.` });
        }
        coverHint.textContent = 'Touchez pour déplier';
    }

    function setFold(next, { instant = false } = {}) {
        if (instant) {
            device.dataset.dragging = '';
            device.dataset.state = next;
            void device.offsetWidth;
            delete device.dataset.dragging;
        } else {
            device.dataset.state = next;
        }

        const isFolded = next === 'closed';
        toggleBtn.textContent = isFolded ? 'Déplier l\'appareil' : 'Plier l\'appareil';
        leftHalf.inert = isFolded;
        front.inert = isFolded;
        back.inert = !isFolded;
        settings.hidden = true;
        gear.setAttribute('aria-expanded', 'false');

        if (isFolded) refreshCover();
        else setTimeout(() => { if (!folded()) coverNotes.replaceChildren(); }, 600);
    }

    const toggleFold = () => setFold(folded() ? 'open' : 'closed');

    // Glissement : la moitié droite suit le doigt ou la souris ; au relâchement, elle finit de se plier ou de se déplier.
    let drag = null;

    device.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || event.target.closest('input, textarea, select, a')) return;
        drag = { x: event.clientX, y: event.clientY, p: folded() ? 1 : 0, last: folded() ? 1 : 0, active: false, id: event.pointerId };
    });

    device.addEventListener('pointermove', (event) => {
        if (!drag) return;
        const dx = event.clientX - drag.x;
        const dy = event.clientY - drag.y;

        if (!drag.active) {
            if (Math.abs(dx) < 10 || Math.abs(dx) < Math.abs(dy) * 1.2) return;
            drag.active = true;
            device.dataset.dragging = '';
            try { device.setPointerCapture(drag.id); } catch (e) { /* pointeur deja relache */ }
            stopStory();
        }

        const half = leftHalf.offsetWidth || 240;
        drag.last = Math.min(1, Math.max(0, drag.p - dx / half));
        device.style.setProperty('--fp', drag.last.toFixed(3));
    });

    const endDrag = () => {
        if (!drag) return;
        const { active, last } = drag;
        drag = null;
        if (!active) return;

        delete device.dataset.dragging;
        setFold(last > 0.5 ? 'closed' : 'open');
        device.style.removeProperty('--fp');
    };

    device.addEventListener('pointerup', endDrag);
    device.addEventListener('pointercancel', endDrag);

    hinge.addEventListener('click', toggleFold);
    coverBtn.addEventListener('click', () => setFold('open'));
    toggleBtn.addEventListener('click', toggleFold);

    /* ------------------------------------------------------------------ alertes, saisie, vocal */

    gear.addEventListener('click', () => {
        settings.hidden = !settings.hidden;
        gear.setAttribute('aria-expanded', String(!settings.hidden));
    });

    root.querySelectorAll('[data-pg-notify]').forEach((box) => {
        box.addEventListener('change', () => {
            notify[box.dataset.pgNotify] = box.checked;
            updateCount();
            const names = { whatsapp: 'WhatsApp', email: 'E-mail', dashboard: 'Tableau de bord' };
            toast({ icon: ICON.bell, tone: 'bg-slate-500', title: `${names[box.dataset.pgNotify]} ${box.checked ? 'activé' : 'désactivé'}`, text: box.checked ? 'Vous serez prévenue ainsi.' : 'Plus d\'alerte de ce type.' });
        });
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        send(input.value);
    });

    ownerForm.addEventListener('submit', (event) => {
        event.preventDefault();
        ownerReply(ownerInput.value);
    });

    micBtn.addEventListener('click', async () => {
        ensureLive();
        const text = VOICE_SAMPLES[voiceIndex++ % VOICE_SAMPLES.length];
        micBtn.classList.add('pg-rec', 'bg-hibiscus-500', 'text-white');
        micBtn.disabled = true;
        status.textContent = 'enregistre…';
        await sleep(animate ? 1300 : 0);
        micBtn.classList.remove('pg-rec', 'bg-hibiscus-500', 'text-white');
        micBtn.disabled = false;
        status.textContent = nlu.handoff ? `${SHOP.owner} a été prévenue` : 'assistant en ligne';
        send(text, { voice: true });
    });

    /* ------------------------------------------------------------------ histoire, remise à zéro */

    function setHint(text) {
        hint.hidden = !text;
        hint.textContent = text ?? '';
    }

    /** Le visiteur touche à l'appareil : l'histoire s'arrête et il prend la main. */
    function stopStory() {
        if (!storyOn) return;
        run += 1;
        storyOn = false;
        if (input.value !== '' && document.activeElement !== input) input.value = '';
        if (ownerInput.value !== '' && document.activeElement !== ownerInput) ownerInput.value = '';
        setHint('À vous de jouer : écrivez à gauche, répondez à droite, pliez l\'appareil ou touchez le micro.');
    }

    function clearLive() {
        turn += 1;
        chain = Promise.resolve();
        nlu = createState();
        told = false;
        leads.forEach((lead) => clearTimeout(lead.timer));
        leads = [];
        leadsBox.querySelectorAll('[data-static]').forEach((node) => node.remove());
        Array.from(leadsBox.children).filter((node) => node !== emptyBox).forEach((node) => node.remove());
        Array.from(body.children).filter((node) => node !== typing && !node.classList.contains('grow')).forEach((node) => node.remove());
        coverNotes.replaceChildren();
        toasts.replaceChildren();
        typing.hidden = true;
        speaker.classList.remove('is-speaking');
        status.textContent = 'assistant en ligne';
        input.value = '';
        setChips([]);
        disableOwner();
        updateCount();
        live = true;
    }

    /** Premier geste sur l'appareil quand seul le contenu statique est affiché : on repart d'une conversation vide. */
    function ensureLive() {
        if (live) return;
        clearLive();
        bubble('bot', rich(`Bonjour ! Je suis l'assistant de la ${SHOP.name}. Je peux vous donner les prix, les horaires ou la livraison, et prendre votre commande.`));
        setChips(['Vos horaires ?', 'Le prix d\'un boubou', 'Livrez-vous à Bobo ?']);
    }

    function resetToZero() {
        stopStory();
        clearLive();
        setFold('open', { instant: true });
        bubble('bot', rich(`Bonjour ! Je suis l'assistant de la ${SHOP.name}. Je peux vous donner les prix, les horaires ou la livraison, et prendre votre commande.`));
        setChips(['Vos horaires ?', 'Le prix d\'un boubou', 'Livrez-vous à Bobo ?']);
        setHint('Conversation vide : essayez « Je prends 2 foulards », « Vos horaires ? » ou « Je veux parler à Awa ».');
    }

    let visible = false;
    let wake = null;
    new IntersectionObserver((entries) => {
        visible = entries[0].isIntersecting;
        if (visible && wake) {
            wake();
            wake = null;
        }
    }, { threshold: 0.35 }).observe(root);
    const untilVisible = () => (visible ? Promise.resolve() : new Promise((resolve) => { wake = resolve; }));

    async function typeInto(field, text, id) {
        field.value = '';
        for (let i = 1; i <= text.length; i++) {
            if (id !== run) return false;
            field.value = text.slice(0, i);
            await sleep(26 + Math.random() * 36);
        }
        await sleep(300);

        return id === run;
    }

    async function playStory() {
        const id = ++run;
        storyOn = true;
        clearLive();
        setFold('closed', { instant: true });
        setHint('L\'histoire se joue toute seule. Touchez l\'appareil pour la reprendre en main.');

        await untilVisible();
        await sleep(1100);
        if (id !== run) return;

        coverNote({ icon: MARK, tone: 'bg-white', title: SHOP.name, text: 'Nouveau message : « Bonsoir, vous livrez à Bobo ? »' });
        await sleep(1900);
        if (id !== run) return;

        setFold('open');
        await sleep(2000);

        for (const step of STORY) {
            if (id !== run) return;

            if (step.user) {
                if (!(await typeInto(input, step.user, id))) return;
                await send(step.user);
                await sleep(900);
            } else {
                if (!(await typeInto(ownerInput, step.owner, id))) return;
                await ownerReply(step.owner);
                await sleep(1100);
            }
        }

        if (id !== run) return;

        // La gérante rend la conversation à l'assistant : le visiteur peut alors continuer à discuter.
        const lead = leads.find((l) => l.kind === 'human');
        if (lead) await resumeAssistant(lead);

        if (id !== run) return;
        storyOn = false;
        setHint('À vous de jouer : écrivez à gauche, répondez à droite, pliez l\'appareil ou touchez le micro.');
    }

    // Le premier vrai geste du visiteur arrête l'histoire (celle-ci n'émet aucun événement). Un simple contact pour
    // faire défiler la page ne compte pas : il faut toucher un bouton, un champ, ou glisser l'appareil.
    ['click', 'keydown', 'focusin'].forEach((type) => root.addEventListener(type, (event) => {
        if (event.isTrusted) stopStory();
    }, true));

    replayBtn.addEventListener('click', () => {
        stopStory();
        playStory();
    });
    resetBtn.addEventListener('click', resetToZero);

    /* ------------------------------------------------------------------ démarrage */

    const tick = () => {
        const date = new Date();
        clock.textContent = date.toLocaleTimeString('fr-FR', { hour: 'numeric', minute: '2-digit' });
        dateEl.textContent = date.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
        leads.forEach((lead) => {
            const when = lead.el.querySelector('[data-when]');
            if (when) when.textContent = whenLabel(lead.at);
        });
    };
    tick();
    setInterval(tick, 20000);

    controls.hidden = false;
    setFold(device.dataset.state === 'closed' ? 'closed' : 'open', { instant: true });
    root.dataset.ready = '';

    if (animate) {
        playStory();
    } else {
        // Sans animation, l'histoire terminée reste affichée ; le premier geste repart d'une conversation vide.
        live = false;
        setHint('Exemple fictif : écrivez un message pour essayer.');
    }
}
