/*!
 * Widget de chat embarquable.
 * Usage : <script src="https://votre-domaine/widget/widget.js" data-bot="pk_xxx" async></script>
 * Options : data-open="true" (ouvre au chargement), data-api="https://..." (surcharge l'URL de l'API).
 * API : window.KoumaWidget.open() / .close() / .toggle()
 * Aucune dependance. Interface isolee dans un Shadow DOM : le CSS du site hote ne casse pas le widget, et inversement.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) {
    var candidates = document.querySelectorAll('script[data-bot]');
    script = candidates[candidates.length - 1];
  }
  var botKey = script && script.getAttribute('data-bot');
  if (!botKey || window.__koumaLoaded === botKey) return;
  window.__koumaLoaded = botKey;

  var base = script.getAttribute('data-api') || new URL(script.src, location.href).origin;
  var api = base.replace(/\/$/, '') + '/api/v1/widget/' + encodeURIComponent(botKey);
  var autoOpen = script.getAttribute('data-open') === 'true';

  var I18N = {
    fr: { placeholder: 'Écrivez votre message…', send: 'Envoyer', close: 'Fermer', open: 'Ouvrir la discussion', human: 'Un conseiller a été prévenu et vous répondra ici.', error: 'Connexion impossible. Réessayez dans un instant.', name: 'Votre nom', phone: 'Votre téléphone', start: 'Démarrer la discussion', contact: 'Pour mieux vous répondre :', agent: 'Conseiller', online: 'En ligne', talk: 'Parler à quelqu\'un', talkText: 'Je veux parler à quelqu\'un', powered: 'Propulsé par' },
    en: { placeholder: 'Type your message…', send: 'Send', close: 'Close', open: 'Open chat', human: 'A team member has been notified and will reply here.', error: 'Connection failed. Please try again shortly.', name: 'Your name', phone: 'Your phone', start: 'Start chat', contact: 'So we can help you better:', agent: 'Agent', online: 'Online', talk: 'Talk to someone', talkText: 'I want to speak to someone', powered: 'Powered by' },
    ar: { placeholder: 'اكتب رسالتك…', send: 'إرسال', close: 'إغلاق', open: 'فتح المحادثة', human: 'تم إبلاغ أحد أعضاء الفريق وسيرد عليك هنا.', error: 'تعذر الاتصال. حاول مرة أخرى بعد قليل.', name: 'اسمك', phone: 'هاتفك', start: 'ابدأ المحادثة', contact: 'لنساعدك بشكل أفضل:', agent: 'مستشار', online: 'متصل', talk: 'التحدث إلى شخص', talkText: 'أريد التحدث إلى شخص', powered: 'مدعوم من' }
  };

  // ---------- stockage local (identifiant visiteur) ----------
  var STORE = 'kouma:' + botKey;
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(STORE)) || {}; } catch (e) { saved = {}; }
  function persist() { try { localStorage.setItem(STORE, JSON.stringify(saved)); } catch (e) { /* stockage indisponible */ } }
  if (!saved.visitor) {
    var bytes = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    saved.visitor = 'v' + Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    persist();
  }

  // ---------- reseau ----------
  function request(method, path, body) {
    return fetch(api + path, {
      method: method,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) { var err = new Error(data.error || data.message || 'http_' + res.status); err.status = res.status; throw err; }
        return data;
      });
    });
  }

  // ---------- etat ----------
  var config = null, t = I18N.fr, root, els = {}, built = false, wantOpen = false;
  var conversation = null, lastId = 0, status = 'bot', pollTimer = null, opened = false, busy = false;

  // API publique disponible tout de suite : les appels faits avant la fin du chargement sont memorises.
  window.KoumaWidget = {
    open: function () { wantOpen = true; if (built) toggle(true); },
    close: function () { wantOpen = false; if (built) toggle(false); },
    toggle: function () { if (built) toggle(); else wantOpen = !wantOpen; }
  };

  request('GET', '/config').then(function (cfg) {
    config = cfg;
    t = I18N[cfg.language] || I18N.fr;
    build();
    built = true;
    if (autoOpen || wantOpen) toggle(true);
  }).catch(function () { /* widget desactive ou origine refusee : on n'affiche rien */ });

  // ---------- construction de l'interface ----------
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') node.textContent = attrs[k];
      else if (k === 'class') node.className = attrs[k];
      else node.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) node.appendChild(c); });
    return node;
  }

  function build() {
    var host = el('div', { id: 'kouma-widget-root' });
    root = host.attachShadow({ mode: 'open' });
    var side = config.position === 'left' ? 'left' : 'right';
    var color = /^#[0-9a-f]{6}$/i.test(config.color) ? config.color : '#2340D9';

    var style = el('style', { text:
      ':host{all:initial}*{box-sizing:border-box;font-family:"Instrument Sans",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}' +
      '.launcher{position:fixed;bottom:20px;' + side + ':20px;width:58px;height:58px;border-radius:50%;border:0;background:' + color + ';color:#fff;cursor:pointer;box-shadow:0 8px 24px rgba(11,19,64,.32);display:flex;align-items:center;justify-content:center;z-index:2147483646;transition:transform .15s}' +
      '.launcher:hover{transform:scale(1.06)}.launcher svg{width:28px;height:28px}' +
      '.panel{position:fixed;bottom:90px;' + side + ':20px;width:380px;max-width:calc(100vw - 16px);height:min(620px,calc(100vh - 112px));background:#fff;border-radius:18px;box-shadow:0 16px 56px rgba(11,19,64,.34);display:none;flex-direction:column;overflow:hidden;z-index:2147483647;color:#0b1340}' +
      '.panel.open{display:flex}' +
      '.head{background:' + color + ';color:#fff;padding:12px 14px;display:flex;align-items:center;gap:10px}' +
      '.av{width:36px;height:36px;border-radius:50%;background:#FFB400;color:#0b1340;font-weight:700;display:flex;align-items:center;justify-content:center;font-size:15px;flex:none}' +
      '.ttl{flex:1;min-width:0;line-height:1.2}.ttl b{display:block;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ttl small{font-size:12px;opacity:.85}.ttl small:before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:#4ade80;margin-inline-end:5px}' +
      '.hbtn{background:rgba(255,255,255,.16);border:0;color:#fff;border-radius:999px;padding:6px 10px;font-size:12px;cursor:pointer;font-weight:600}.hbtn:hover{background:rgba(255,255,255,.28)}' +
      '.x{background:none;border:0;color:#fff;font-size:24px;line-height:1;cursor:pointer;padding:0 2px}' +
      '.msgs{flex:1;overflow-y:auto;padding:14px;background:#f1f4fb;display:flex;flex-direction:column;gap:8px;scroll-behavior:smooth}' +
      '.m{max-width:86%;padding:9px 13px;border-radius:14px;font-size:14.5px;line-height:1.5;word-wrap:break-word;overflow-wrap:anywhere;animation:in .22s ease-out}' +
      '.m p{margin:0 0 6px}.m p:last-child{margin:0}.m ul,.m ol{margin:4px 0 6px;padding-inline-start:20px}.m li{margin:2px 0}.m a{color:inherit;text-decoration:underline;text-underline-offset:2px}.m strong{font-weight:650}' +
      '.bot,.agent{align-self:flex-start;background:#fff;border:1px solid #e2e8f0;border-bottom-left-radius:4px}' +
      '.agent{border-color:' + color + '}.who{display:block;font-size:11px;font-weight:650;color:' + color + ';margin-bottom:2px}' +
      '.user{align-self:flex-end;background:' + color + ';color:#fff;border-bottom-right-radius:4px}' +
      '.note{align-self:center;font-size:12px;color:#64748b;text-align:center;padding:2px 8px}' +
      '.src{font-size:11.5px;color:#64748b;margin-top:7px}.src a{color:#475569}' +
      '.chips{display:flex;flex-wrap:wrap;gap:6px;align-self:flex-start;animation:in .3s ease-out}' +
      '.chips button{border:1.5px solid ' + color + ';color:' + color + ';background:#fff;border-radius:999px;padding:6px 12px;font-size:13px;font-weight:550;cursor:pointer}' +
      '.chips button:hover{background:' + color + ';color:#fff}' +
      'form.send{display:flex;gap:8px;padding:10px;border-top:1px solid #e2e8f0;background:#fff}' +
      'form.send input{flex:1;border:1px solid #cbd5e1;border-radius:999px;padding:10px 15px;font-size:14.5px;outline:none;min-width:0}' +
      'form.send input:focus{border-color:' + color + ';box-shadow:0 0 0 3px ' + color + '22}' +
      'form.send button{border:0;background:' + color + ';color:#fff;border-radius:999px;padding:0 17px;font-size:14px;font-weight:650;cursor:pointer}' +
      'form.send button:disabled{opacity:.5;cursor:default}' +
      '.pow{padding:6px;text-align:center;font-size:11.5px;color:#64748b;background:#fff;border-top:1px solid #f1f5f9}.pow a{color:#0b1340;font-weight:650;text-decoration:none}.pow a:hover{text-decoration:underline}' +
      '.typing{align-self:flex-start;display:flex;gap:4px;padding:12px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:14px}' +
      '.typing i{width:6px;height:6px;border-radius:50%;background:#94a3b8;animation:b 1.2s infinite}.typing i:nth-child(2){animation-delay:.15s}.typing i:nth-child(3){animation-delay:.3s}' +
      '@keyframes b{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-4px)}}@keyframes in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}' +
      '.pre{padding:18px;display:flex;flex-direction:column;gap:10px;font-size:14px;background:#fff;border-radius:12px}.pre input{border:1px solid #cbd5e1;border-radius:8px;padding:10px 12px;font-size:14px}' +
      '.pre button{border:0;background:' + color + ';color:#fff;border-radius:8px;padding:11px;font-weight:650;font-size:14px;cursor:pointer}' +
      '@media (prefers-reduced-motion:reduce){.typing i,.m,.chips{animation:none}.launcher{transition:none}.msgs{scroll-behavior:auto}}'
    });

    els.launcher = el('button', { class: 'launcher', type: 'button', 'aria-label': t.open, 'aria-expanded': 'false' });
    els.launcher.innerHTML = '<svg viewBox="0 0 40 40" aria-hidden="true"><path fill="currentColor" d="M20 3C10.6 3 3 9.9 3 18.4c0 4.6 2.2 8.7 5.7 11.5L7 36.5c-.2.8.7 1.4 1.4.9l6.4-3.8c1.7.5 3.4.7 5.2.7 9.4 0 17-6.9 17-15.4S29.4 3 20 3Z"/><circle cx="13" cy="18.5" r="2.3" fill="' + color + '"/><circle cx="20" cy="18.5" r="2.3" fill="' + color + '"/><circle cx="27" cy="18.5" r="2.3" fill="' + color + '"/></svg>';
    els.launcher.addEventListener('click', function () { toggle(); });

    els.close = el('button', { class: 'x', type: 'button', 'aria-label': t.close, text: '×' });
    els.close.addEventListener('click', function () { toggle(false); });
    els.talk = el('button', { class: 'hbtn', type: 'button', text: t.talk });
    els.talk.addEventListener('click', function () { send(t.talkText); });

    var initial = (config.title || config.name || '?').trim().charAt(0).toUpperCase();
    var head = el('div', { class: 'head' }, [
      el('span', { class: 'av', text: initial, 'aria-hidden': 'true' }),
      el('div', { class: 'ttl' }, [el('b', { text: config.title }), el('small', { text: t.online })]),
      els.talk, els.close
    ]);

    els.msgs = el('div', { class: 'msgs', role: 'log', 'aria-live': 'polite' });
    els.input = el('input', { type: 'text', placeholder: t.placeholder, maxlength: '2000', 'aria-label': t.placeholder, autocomplete: 'off' });
    els.sendBtn = el('button', { type: 'submit', text: t.send });
    els.form = el('form', { class: 'send' }, [els.input, els.sendBtn]);
    els.form.addEventListener('submit', function (e) { e.preventDefault(); send(els.input.value); });

    var parts = [head, els.msgs, els.form];
    if (config.branding && config.brand) {
      var link = el('a', { href: config.brand.url, target: '_blank', rel: 'noopener', text: config.brand.name });
      parts.push(el('div', { class: 'pow' }, [document.createTextNode(t.powered + ' '), link]));
    }

    els.panel = el('div', { class: 'panel', role: 'dialog', 'aria-label': config.title }, parts);
    if (config.rtl) els.panel.setAttribute('dir', 'rtl');
    els.panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggle(false); });

    root.appendChild(style);
    root.appendChild(els.panel);
    root.appendChild(els.launcher);
    document.body.appendChild(host);
  }

  // ---------- rendu du texte (Markdown minimal, sans innerHTML) ----------
  var INLINE = /\*\*(.+?)\*\*|\*(?!\s)([^*\n]+?)\*|(https?:\/\/[^\s<>()]+)|([\w.+-]+@[\w-]+\.[\w.-]+)|(\+\d[\d\s().-]{6,}\d|\b\d{2}(?:[\s.-]\d{2}){3}\b)/g;

  function inline(text, parent) {
    var last = 0, m;
    INLINE.lastIndex = 0;
    while ((m = INLINE.exec(text))) {
      if (m.index > last) parent.appendChild(document.createTextNode(text.slice(last, m.index)));
      if (m[1] || m[2]) parent.appendChild(el('strong', { text: m[1] || m[2] }));
      else if (m[3]) parent.appendChild(el('a', { href: m[3], target: '_blank', rel: 'noopener noreferrer nofollow', text: m[3].replace(/^https?:\/\//, '') }));
      else if (m[4]) parent.appendChild(el('a', { href: 'mailto:' + m[4], text: m[4] }));
      else parent.appendChild(el('a', { href: 'tel:' + m[5].replace(/[^\d+]/g, ''), text: m[5] }));
      last = INLINE.lastIndex;
    }
    if (last < text.length) parent.appendChild(document.createTextNode(text.slice(last)));
  }

  function renderText(container, text) {
    var list = null, listType = null, para = null;
    String(text).split('\n').forEach(function (line) {
      var bullet = line.match(/^\s*(?:[-•]|\*(?=\s))\s*(.+)$/);
      var num = line.match(/^\s*(\d+)[.)]\s+(.+)$/);
      var head = line.match(/^\s*#{1,6}\s+(.+)$/);
      if (bullet || num) {
        para = null;
        var type = num ? 'ol' : 'ul';
        if (!list || listType !== type) { list = el(type); listType = type; container.appendChild(list); }
        var li = el('li'); inline(num ? num[2] : bullet[1], li); list.appendChild(li);
      } else if (line.trim() === '') {
        list = null; para = null;
      } else {
        list = null;
        if (head) { para = null; var p = el('p'); var s = el('strong'); inline(head[1], s); p.appendChild(s); container.appendChild(p); }
        else {
          if (!para) { para = el('p'); container.appendChild(para); } else para.appendChild(document.createElement('br'));
          inline(line, para);
        }
      }
    });
  }

  function clearChips() { var c = els.msgs.querySelector('.chips'); if (c) c.remove(); }

  function addMessage(msg, withChips) {
    var kind = msg.role === 'user' ? 'user' : (msg.role === 'agent' ? 'agent' : 'bot');
    var bubble = el('div', { class: 'm ' + kind });
    if (kind === 'agent') bubble.appendChild(el('span', { class: 'who', text: t.agent }));
    renderText(bubble, msg.content);
    if (msg.sources && msg.sources.length) {
      var src = el('div', { class: 'src' });
      msg.sources.slice(0, 3).forEach(function (s, i) {
        if (i) src.appendChild(document.createTextNode(' · '));
        src.appendChild(el('a', { href: s.url, target: '_blank', rel: 'noopener noreferrer', text: s.title || s.url }));
      });
      bubble.appendChild(src);
    }
    clearChips();
    els.msgs.appendChild(bubble);
    if (msg.id && msg.id > lastId) lastId = msg.id;
    if (withChips !== false && kind !== 'user' && msg.suggestions && msg.suggestions.length) chips(msg.suggestions);
    scroll();
  }

  function chips(list) {
    var box = el('div', { class: 'chips' });
    list.slice(0, 3).forEach(function (q) {
      var b = el('button', { type: 'button', text: q });
      b.addEventListener('click', function () { send(q); });
      box.appendChild(b);
    });
    els.msgs.appendChild(box);
  }

  function note(text) { els.msgs.appendChild(el('div', { class: 'note', text: text })); scroll(); }
  function scroll() { els.msgs.scrollTop = els.msgs.scrollHeight; }
  function typing(on) {
    var existing = els.msgs.querySelector('.typing');
    if (on && !existing) { clearChips(); els.msgs.appendChild(el('div', { class: 'typing' }, [el('i'), el('i'), el('i')])); scroll(); }
    if (!on && existing) existing.remove();
  }

  // ---------- comportement ----------
  function toggle(force) {
    opened = typeof force === 'boolean' ? force : !opened;
    els.panel.classList.toggle('open', opened);
    els.launcher.setAttribute('aria-expanded', String(opened));
    if (opened) { start(); setTimeout(function () { els.input.focus(); }, 50); schedulePoll(); }
    else stopPoll();
  }

  function start() {
    if (conversation || busy) return;
    if (config.collect_contact && !saved.contactDone) { showContactForm(); return; }
    open({});
  }

  function showContactForm() {
    els.form.style.display = 'none';
    var name = el('input', { type: 'text', placeholder: t.name, 'aria-label': t.name, autocomplete: 'name' });
    var phone = el('input', { type: 'tel', placeholder: t.phone, 'aria-label': t.phone, autocomplete: 'tel' });
    var btn = el('button', { type: 'submit', text: t.start });
    var form = el('form', { class: 'pre' }, [el('div', { text: t.contact }), name, phone, btn]);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      saved.contactDone = true; persist();
      form.remove(); els.form.style.display = '';
      open({ name: name.value.trim() || undefined, phone: phone.value.trim() || undefined });
    });
    els.msgs.appendChild(form);
  }

  function open(contact) {
    busy = true;
    var payload = { visitor_id: saved.visitor };
    if (contact.name) payload.name = contact.name;
    if (contact.phone) payload.phone = contact.phone;

    request('POST', '/conversations', payload).then(function (data) {
      conversation = data.token; status = data.status;
      els.msgs.innerHTML = '';
      if (!data.messages.length) {
        addMessage({ role: 'assistant', content: config.welcome }, false);
        chips(config.suggested || []);
      } else {
        data.messages.forEach(function (m, i) { addMessage(m, i === data.messages.length - 1); });
      }
      if (status === 'needs_human' || status === 'human') schedulePoll();
    }).catch(function () { note(t.error); }).then(function () { busy = false; });
  }

  function send(text) {
    text = String(text || '').trim();
    if (!text || !conversation || busy) return;
    busy = true;
    els.input.value = '';
    els.sendBtn.disabled = true;
    addMessage({ role: 'user', content: text });
    typing(true);
    var shownAt = Date.now();

    request('POST', '/conversations/' + conversation + '/messages', { content: text }).then(function (data) {
      // Un court delai minimum : une reponse instantanee parait suspecte, et l'indicateur evite un « saut ».
      var wait = Math.max(0, 650 - (Date.now() - shownAt));
      return new Promise(function (resolve) { setTimeout(function () { resolve(data); }, wait); });
    }).then(function (data) {
      typing(false);
      if (data.message) addMessage(data.message);
      var wasHuman = status === 'needs_human' || status === 'human';
      status = data.status;
      if (!data.message || (!wasHuman && (status === 'needs_human' || status === 'human'))) note(t.human);
      schedulePoll();
    }).catch(function () {
      typing(false);
      note(t.error);
    }).then(function () { busy = false; els.sendBtn.disabled = false; els.input.focus(); });
  }

  // Un conseiller peut repondre depuis le tableau de bord : on interroge l'API tant que la conversation est confiee a un humain.
  function schedulePoll() {
    stopPoll();
    if (!opened || !conversation || !(status === 'needs_human' || status === 'human')) return;
    pollTimer = setTimeout(poll, 5000);
  }
  function stopPoll() { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } }
  function poll() {
    request('GET', '/conversations/' + conversation + '/messages?after=' + lastId).then(function (data) {
      status = data.status;
      data.messages.forEach(function (m) { addMessage(m, false); });
    }).catch(function () { /* on reessaie au prochain tour */ }).then(schedulePoll);
  }
})();
