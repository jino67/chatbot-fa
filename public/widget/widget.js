/*!
 * Widget de chat embarquable.
 * Usage : <script src="https://votre-domaine/widget/widget.js" data-bot="pk_xxx" async></script>
 * Options : data-open="true" (ouvre au chargement), data-api="https://..." (surcharge l'URL de l'API).
 * API : window.KoumaWidget.open() / .close() / .toggle()
 * Aucune dependance. Interface isolee dans un Shadow DOM : le CSS du site hote ne casse pas le widget, et inversement.
 * Fonctions : reponses qui s'ecrivent, message vocal (micro), ecoute des reponses, copie, avis (pouces), choix de la
 * langue, nouvelle conversation, lien pour continuer sur WhatsApp. Ecrit en ES5 : il doit tourner sur les
 * navigateurs Android anciens.
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
  var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  var I18N = {
    fr: { placeholder: 'Écrivez votre message…', send: 'Envoyer', close: 'Fermer', open: 'Ouvrir la discussion', human: 'Un conseiller a été prévenu et vous répondra ici.', error: 'Connexion impossible. Réessayez dans un instant.', name: 'Votre nom', phone: 'Votre téléphone', start: 'Démarrer la discussion', contact: 'Pour mieux vous répondre :', agent: 'Conseiller', online: 'En ligne', writing: 'écrit…', talk: 'Parler à quelqu\'un', talkText: 'Je veux parler à quelqu\'un', powered: 'Propulsé par', menu: 'Options', newChat: 'Nouvelle conversation', whatsapp: 'Continuer sur WhatsApp', waHello: 'Bonjour', language: 'Langue', copy: 'Copier', copied: 'Copié', good: 'Réponse utile', bad: 'Réponse à améliorer', thanks: 'Merci pour votre avis.', listen: 'Écouter', playing: 'Arrêter', mic: 'Message vocal', micSend: 'Envoyer le vocal', micCancel: 'Annuler', micDenied: 'Le micro est bloqué. Autorisez-le dans votre navigateur pour parler.', voiceFail: 'Le message vocal n\'a pas pu être envoyé. Écrivez votre question ou réessayez.', transcribing: 'Transcription…', reset: 'Nouvelle conversation.' },
    en: { placeholder: 'Type your message…', send: 'Send', close: 'Close', open: 'Open chat', human: 'A team member has been notified and will reply here.', error: 'Connection failed. Please try again shortly.', name: 'Your name', phone: 'Your phone', start: 'Start chat', contact: 'So we can help you better:', agent: 'Agent', online: 'Online', writing: 'typing…', talk: 'Talk to someone', talkText: 'I want to speak to someone', powered: 'Powered by', menu: 'Options', newChat: 'New conversation', whatsapp: 'Continue on WhatsApp', waHello: 'Hello', language: 'Language', copy: 'Copy', copied: 'Copied', good: 'Helpful answer', bad: 'Answer to improve', thanks: 'Thanks for your feedback.', listen: 'Listen', playing: 'Stop', mic: 'Voice message', micSend: 'Send voice message', micCancel: 'Cancel', micDenied: 'The microphone is blocked. Allow it in your browser to speak.', voiceFail: 'The voice message could not be sent. Type your question or try again.', transcribing: 'Transcribing…', reset: 'New conversation.' },
    ar: { placeholder: 'اكتب رسالتك…', send: 'إرسال', close: 'إغلاق', open: 'فتح المحادثة', human: 'تم إبلاغ أحد أعضاء الفريق وسيرد عليك هنا.', error: 'تعذر الاتصال. حاول مرة أخرى بعد قليل.', name: 'اسمك', phone: 'هاتفك', start: 'ابدأ المحادثة', contact: 'لنساعدك بشكل أفضل:', agent: 'مستشار', online: 'متصل', writing: 'يكتب…', talk: 'التحدث إلى شخص', talkText: 'أريد التحدث إلى شخص', powered: 'مدعوم من', menu: 'خيارات', newChat: 'محادثة جديدة', whatsapp: 'المتابعة على واتساب', waHello: 'مرحبا', language: 'اللغة', copy: 'نسخ', copied: 'تم النسخ', good: 'إجابة مفيدة', bad: 'إجابة تحتاج إلى تحسين', thanks: 'شكرا على رأيك.', listen: 'استماع', playing: 'إيقاف', mic: 'رسالة صوتية', micSend: 'إرسال الرسالة الصوتية', micCancel: 'إلغاء', micDenied: 'الميكروفون محظور. اسمح به في المتصفح للتحدث.', voiceFail: 'تعذر إرسال الرسالة الصوتية. اكتب سؤالك أو حاول مجددا.', transcribing: 'جار التفريغ…', reset: 'محادثة جديدة.' }
  };

  // ---------- stockage local (identifiant visiteur, langue choisie) ----------
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
  function handle(res) {
    return res.json().catch(function () { return {}; }).then(function (data) {
      if (!res.ok) { var err = new Error(data.error || data.message || 'http_' + res.status); err.status = res.status; err.data = data; throw err; }
      return data;
    });
  }

  function request(method, path, body) {
    return fetch(api + path, {
      method: method,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(handle);
  }

  function upload(path, form) {
    return fetch(api + path, { method: 'POST', headers: { 'Accept': 'application/json' }, body: form }).then(handle);
  }

  // ---------- etat ----------
  var config = null, t = I18N.fr, root, els = {}, built = false, wantOpen = false;
  var conversation = null, lastId = 0, status = 'bot', pollTimer = null, opened = false, busy = false;
  var lang = null, lastQuestion = '', recorder = null, recTimer = 0, recStart = 0, recCancelled = false, player = null, teaserTimer = 0;

  // API publique disponible tout de suite : les appels faits avant la fin du chargement sont memorises.
  window.KoumaWidget = {
    open: function () { wantOpen = true; if (built) toggle(true); },
    close: function () { wantOpen = false; if (built) toggle(false); },
    toggle: function () { if (built) toggle(); else wantOpen = !wantOpen; }
  };

  request('GET', '/config').then(function (cfg) {
    config = cfg;
    var langs = cfg.languages || [];
    lang = (saved.lang && langs.some(function (l) { return l.code === saved.lang; })) ? saved.lang : (cfg.language || 'fr');
    t = I18N[lang] || I18N.fr;
    build();
    built = true;
    if (autoOpen || wantOpen) toggle(true); else scheduleTeaser();
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

  function svg(paths, size) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 20 20');
    s.setAttribute('width', size || 16);
    s.setAttribute('height', size || 16);
    s.setAttribute('fill', 'none');
    s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', '1.8');
    s.setAttribute('stroke-linecap', 'round');
    s.setAttribute('stroke-linejoin', 'round');
    s.setAttribute('aria-hidden', 'true');
    s.innerHTML = paths;
    return s;
  }

  var ICONS = {
    copy: '<rect x="7" y="7" width="9" height="9" rx="2"/><path d="M13 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>',
    check: '<path d="m4.5 10.5 3.5 3.5 7.5-8"/>',
    up: '<path d="M7 9v7H4V9ZM7 9l3-6c1.2 0 2 .9 1.8 2L11.4 8H15a1.5 1.5 0 0 1 1.5 1.8l-1 5A1.5 1.5 0 0 1 14 16H7"/>',
    down: '<path d="M7 11V4H4v7ZM7 11l3 6c1.2 0 2-.9 1.8-2L11.4 12H15a1.5 1.5 0 0 0 1.5-1.8l-1-5A1.5 1.5 0 0 0 14 4H7"/>',
    listen: '<path d="M4 8v4h3l4 3.5v-11L7 8ZM14 7.5a3.5 3.5 0 0 1 0 5M15.8 5.5a6.3 6.3 0 0 1 0 9"/>',
    stop: '<rect x="5.5" y="5.5" width="9" height="9" rx="1.5" fill="currentColor"/>',
    mic: '<rect x="7.5" y="2.5" width="5" height="9" rx="2.5"/><path d="M4.5 9.5a5.5 5.5 0 0 0 11 0M10 15v2.5"/>',
    dots: '<circle cx="4.5" cy="10" r="1.3" fill="currentColor"/><circle cx="10" cy="10" r="1.3" fill="currentColor"/><circle cx="15.5" cy="10" r="1.3" fill="currentColor"/>',
    globe: '<circle cx="10" cy="10" r="7"/><path d="M3 10h14M10 3c2.2 2 3.2 4.4 3.2 7S12.2 15 10 17c-2.2-2-3.2-4.4-3.2-7S7.8 5 10 3Z"/>',
    refresh: '<path d="M16 10a6 6 0 1 1-1.8-4.3M16 3.5v3.2h-3.2"/>',
    whatsapp: '<path d="M3.5 16.5 4.6 13A6.5 6.5 0 1 1 7 15.4Z"/><path d="M7.7 8.2c.3 1.6 1.6 3 3.2 3.6l1.2-.9.9.6c-.3.9-1.1 1.4-2 1.3-2.3-.3-4.3-2.3-4.6-4.6-.1-.9.4-1.7 1.3-2l.6.9Z"/>',
    send: '<path d="M10 16V4M5 9l5-5 5 5"/>',
    x: '<path d="m5 5 10 10M15 5 5 15"/>'
  };

  function ico(name, size) { return svg(ICONS[name], size); }

  function build() {
    var host = el('div', { id: 'kouma-widget-root' });
    root = host.attachShadow({ mode: 'open' });
    var side = config.position === 'left' ? 'left' : 'right';
    var color = /^#[0-9a-f]{6}$/i.test(config.color) ? config.color : '#2340D9';

    var style = el('style', { text:
      ':host{all:initial}*{box-sizing:border-box;font-family:"Instrument Sans",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}' +
      'button{font:inherit}' +
      // Lanceur : une bulle dont le coin bas reste presque droit ; elle devient un cercle a l'ouverture.
      '.launcher{position:fixed;bottom:20px;' + side + ':20px;width:60px;height:60px;border:0;background:' + color + ';color:#fff;cursor:pointer;box-shadow:0 10px 28px rgba(11,19,64,.34);display:flex;align-items:center;justify-content:center;z-index:2147483646;border-radius:50% 50% 50% 50%/50% 50% 50% 50%;border-bottom-' + side + '-radius:14px;transition:border-radius .5s cubic-bezier(.3,1.4,.5,1),transform .25s,box-shadow .25s;animation:hop 9s ease-in-out 4s infinite}' +
      '.launcher:hover{transform:scale(1.07);border-bottom-' + side + '-radius:50%}.launcher:focus-visible,.icon:focus-visible,.chips button:focus-visible,.tool:focus-visible,.item:focus-visible{outline:2px solid #FFB400;outline-offset:2px}' +
      '.launcher.is-open{border-bottom-' + side + '-radius:50%;animation:none}' +
      '.launcher .mk{width:34px;height:34px;overflow:visible}.launcher .mk path{transition:transform .5s cubic-bezier(.3,1.5,.5,1)}.launcher:hover .mk .a{transform:translate(-2px,-3px)}.launcher:hover .mk .b{transform:translate(2px,3px)}' +
      '.launcher .cx{position:absolute;opacity:0;transform:rotate(-90deg) scale(.5);transition:opacity .25s,transform .4s cubic-bezier(.3,1.4,.5,1)}.launcher.is-open .cx{opacity:1;transform:none}.launcher.is-open .mk{opacity:0;transform:rotate(90deg) scale(.5)}.launcher .mk{transition:opacity .2s,transform .4s}' +
      '@keyframes hop{0%,8%,100%{transform:translateY(0)}2%{transform:translateY(-14px) scale(.96,1.05)}4%{transform:translateY(0) scale(1.1,.9)}6%{transform:translateY(-6px)}}' +
      // Bulle d'accroche
      '.teaser{position:fixed;bottom:92px;' + side + ':20px;max-width:min(270px,calc(100vw - 40px));background:#fff;color:#0b1340;border-radius:18px;border-bottom-' + side + '-radius:5px;padding:12px 32px 12px 14px;font-size:14px;line-height:1.4;box-shadow:0 12px 34px rgba(11,19,64,.28);z-index:2147483645;cursor:pointer;animation:pop .5s cubic-bezier(.2,1.3,.3,1);transform-origin:bottom ' + side + '}' +
      '.teaser .tx{position:absolute;top:6px;inset-inline-end:6px;width:22px;height:22px;border:0;background:none;color:#64748b;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center}.teaser .tx:hover{background:#f1f5f9}' +
      // Panneau : s'ouvre depuis le lanceur, avec un leger rebond
      '.panel{position:fixed;bottom:92px;' + side + ':20px;width:390px;max-width:calc(100vw - 16px);height:min(640px,calc(100vh - 114px));background:#fff;border-radius:22px;border-bottom-' + side + '-radius:6px;box-shadow:0 18px 60px rgba(11,19,64,.36);display:flex;flex-direction:column;overflow:hidden;z-index:2147483647;color:#0b1340;visibility:hidden;opacity:0;pointer-events:none;transform:translateY(18px) scale(.86);transform-origin:bottom ' + side + ';transition:transform .45s cubic-bezier(.2,1.25,.3,1),opacity .22s ease,visibility 0s linear .45s}' +
      '.panel.open{visibility:visible;opacity:1;pointer-events:auto;transform:none;transition:transform .45s cubic-bezier(.2,1.25,.3,1),opacity .22s ease,visibility 0s}' +
      '@media (max-width:480px){.panel{inset:0;bottom:0;width:100%;max-width:none;height:100%;border-radius:0;transform:translateY(40px)}.launcher.is-open{display:none}}' +
      // En-tete
      '.head{background:' + color + ';color:#fff;padding:12px 10px 12px 14px;display:flex;align-items:center;gap:10px;position:relative}' +
      '.av{width:38px;height:38px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;flex:none}.av svg{width:24px;height:24px;overflow:visible}.av.talk svg .a{animation:mouthA .5s ease-in-out infinite alternate}.av.talk svg .b{animation:mouthB .5s ease-in-out infinite alternate}' +
      '@keyframes mouthA{to{transform:translate(-1.5px,-3px)}}@keyframes mouthB{to{transform:translate(1.5px,3px)}}' +
      '.ttl{flex:1;min-width:0;line-height:1.2}.ttl b{display:block;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ttl small{font-size:12px;opacity:.9}.ttl small:before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:#4ade80;margin-inline-end:5px}' +
      '.icon{background:rgba(255,255,255,.14);border:0;color:#fff;width:34px;height:34px;border-radius:12px 4px 12px 4px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:border-radius .4s cubic-bezier(.3,1.4,.5,1),background .2s;flex:none}.icon:hover{background:rgba(255,255,255,.26);border-radius:4px 12px 4px 12px}' +
      '.lang{width:auto;padding:0 10px;gap:5px;font-size:12px;font-weight:650}' +
      // Menus
      '.pop{position:absolute;top:56px;inset-inline-end:10px;min-width:210px;background:#fff;color:#0b1340;border-radius:16px 6px 16px 16px;box-shadow:0 14px 40px rgba(11,19,64,.3);padding:6px;z-index:5;display:none;animation:pop .28s cubic-bezier(.2,1.3,.3,1);transform-origin:top right}[dir=rtl] .pop{transform-origin:top left}.pop.show{display:block}' +
      '.item{display:flex;align-items:center;gap:10px;width:100%;border:0;background:none;padding:10px 12px;border-radius:12px 4px 12px 4px;font-size:14px;cursor:pointer;text-align:start;color:#0b1340;transition:background .15s}.item:hover{background:#eef1fe}.item svg{flex:none;color:' + color + '}.item[aria-checked="true"]{background:#eef1fe;font-weight:650}' +
      '.item small{margin-inline-start:auto;color:#64748b;font-size:11.5px}' +
      // Messages
      '.msgs{flex:1;overflow-y:auto;padding:14px 14px 8px;background:#f1f4fb;display:flex;flex-direction:column;gap:6px;scroll-behavior:smooth;scrollbar-width:thin}' +
      '.row{display:flex;flex-direction:column;max-width:88%;animation:in .3s cubic-bezier(.2,1.2,.3,1)}.row.user{align-self:flex-end;align-items:flex-end}.row.bot,.row.agent{align-self:flex-start}' +
      '.m{padding:9px 13px;font-size:14.5px;line-height:1.5;word-wrap:break-word;overflow-wrap:anywhere}' +
      '.m p{margin:0 0 6px}.m p:last-child{margin:0}.m ul,.m ol{margin:4px 0 6px;padding-inline-start:20px}.m li{margin:2px 0}.m a{color:inherit;text-decoration:underline;text-underline-offset:2px}.m strong{font-weight:650}' +
      '.bot .m,.agent .m{background:#fff;border:1px solid #e2e8f0;border-radius:6px 18px 18px 18px}.agent .m{border-color:' + color + '}.who{display:block;font-size:11px;font-weight:650;color:' + color + ';margin-bottom:2px}' +
      '.user .m{background:' + color + ';color:#fff;border-radius:18px 18px 5px 18px}' +
      '.voice{display:flex;align-items:center;gap:8px}.wave{display:inline-flex;align-items:center;gap:2px;height:18px}.wave i{display:block;width:3px;border-radius:2px;background:currentColor;opacity:.85;animation:wv .9s ease-in-out infinite}.wave i:nth-child(2n){animation-delay:.12s}.wave i:nth-child(3n){animation-delay:.24s}@keyframes wv{0%,100%{height:5px}50%{height:16px}}' +
      '.vt{display:block;font-size:12.5px;opacity:.9;margin-top:4px;font-style:italic}' +
      '.note{align-self:center;font-size:12px;color:#64748b;text-align:center;padding:2px 8px;animation:in .3s ease-out}' +
      '.src{font-size:11.5px;color:#64748b;margin-top:7px}.src a{color:#475569}' +
      '.media{display:flex;flex-wrap:wrap;gap:8px;margin-top:9px}.ph{display:block;width:150px;text-decoration:none;color:inherit}.ph img{display:block;width:150px;height:150px;object-fit:cover;border-radius:12px;border:1px solid #e2e8f0;background:#f1f5f9}.ph span{display:block;font-size:11.5px;line-height:1.35;margin-top:4px;color:#475569}' +
      '.tools{display:flex;gap:2px;margin-top:3px;opacity:.0;transition:opacity .2s}.row:hover .tools,.row.last .tools,.tools:focus-within{opacity:1}' +
      '.tool{border:0;background:none;color:#64748b;width:28px;height:26px;border-radius:9px 3px 9px 3px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s,border-radius .3s}.tool:hover{background:#e2e8f0;color:#0b1340;border-radius:3px 9px 3px 9px}.tool.on{color:' + color + ';background:#e6ebfb}.tool.ok{color:#059669}' +
      '.chips{display:flex;flex-wrap:wrap;gap:6px;align-self:flex-start;margin-top:2px}' +
      '.chips button{border:1.5px solid ' + color + ';color:' + color + ';background:#fff;border-radius:14px 4px 14px 4px;padding:6px 12px;font-size:13px;font-weight:600;cursor:pointer;animation:pop .4s cubic-bezier(.2,1.3,.3,1) both;transition:border-radius .4s cubic-bezier(.3,1.4,.5,1),background .15s,color .15s}' +
      '.chips button:hover{background:' + color + ';color:#fff;border-radius:4px 14px 4px 14px}' +
      '.cta{display:inline-flex;align-items:center;gap:8px;align-self:flex-start;margin-top:4px;background:#12A574;color:#fff;border:0;border-radius:14px 4px 14px 4px;padding:8px 14px;font-size:13.5px;font-weight:650;cursor:pointer;animation:pop .4s cubic-bezier(.2,1.3,.3,1) both;text-decoration:none}.cta:hover{filter:brightness(1.08)}' +
      // Saisie
      'form.send{display:flex;align-items:center;gap:8px;padding:10px;border-top:1px solid #e2e8f0;background:#fff;position:relative}' +
      'form.send input{flex:1;border:1px solid #cbd5e1;border-radius:999px;padding:10px 15px;font-size:16px;outline:none;min-width:0;background:#fff;color:#0b1340}@media (min-width:481px){form.send input{font-size:14.5px}}' +
      'form.send input:focus{border-color:' + color + ';box-shadow:0 0 0 3px ' + color + '22}' +
      '.round{border:0;width:40px;height:40px;flex:none;cursor:pointer;display:flex;align-items:center;justify-content:center;border-radius:50%;transition:border-radius .4s cubic-bezier(.3,1.4,.5,1),transform .15s,background .2s,opacity .2s}.round:hover:not(:disabled){border-radius:14px 5px 14px 5px}.round:active:not(:disabled){transform:scale(.92)}' +
      '.round.go{background:' + color + ';color:#fff}.round.go:disabled{opacity:.45;cursor:default}.round.mic{background:#eef1fe;color:' + color + '}' +
      '.rec{position:absolute;inset:0;display:none;align-items:center;gap:10px;padding:0 10px;background:#fff;z-index:2}.rec.on{display:flex}.rec .dot{width:11px;height:11px;border-radius:50%;background:#E8336D;animation:pulse 1s ease-in-out infinite}.rec .tm{font-variant-numeric:tabular-nums;font-size:14px;font-weight:650;min-width:40px}.rec .wave{color:#E8336D;flex:1}' +
      '@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(232,51,109,.5)}50%{box-shadow:0 0 0 7px rgba(232,51,109,0)}}' +
      '.pow{padding:6px;text-align:center;font-size:11.5px;color:#64748b;background:#fff;border-top:1px solid #f1f5f9}.pow a{color:#0b1340;font-weight:650;text-decoration:none}.pow a:hover{text-decoration:underline}' +
      '.typing{align-self:flex-start;display:flex;gap:4px;padding:12px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:6px 18px 18px 18px;animation:in .25s ease-out}' +
      '.typing i{width:6px;height:6px;border-radius:50%;background:#94a3b8;animation:b 1.2s infinite}.typing i:nth-child(2){animation-delay:.15s}.typing i:nth-child(3){animation-delay:.3s}' +
      '@keyframes b{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-4px)}}@keyframes in{from{opacity:0;transform:translateY(8px) scale(.97)}to{opacity:1;transform:none}}@keyframes pop{from{opacity:0;transform:scale(.85)}to{opacity:1;transform:none}}' +
      '.pre{padding:18px;display:flex;flex-direction:column;gap:10px;font-size:14px;background:#fff;border-radius:16px 16px 16px 5px}.pre input{border:1px solid #cbd5e1;border-radius:10px 4px 10px 4px;padding:10px 12px;font-size:16px}' +
      '.pre button{border:0;background:' + color + ';color:#fff;border-radius:14px 4px 14px 4px;padding:11px;font-weight:650;font-size:14px;cursor:pointer}' +
      '.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}' +
      '@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}.msgs{scroll-behavior:auto}}'
    });

    // Marque : le « o » qui parle (deux demi-cercles)
    var mark = function (top, bottom, cls) {
      var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      s.setAttribute('viewBox', '0 0 48 48');
      s.setAttribute('class', cls || 'mk');
      s.setAttribute('aria-hidden', 'true');
      s.innerHTML = '<path class="a" d="M5 21a17 17 0 0 1 34 0Z" fill="' + top + '"/><path class="b" d="M9 27a17 17 0 0 0 34 0Z" fill="' + bottom + '"/>';
      return s;
    };

    els.launcher = el('button', { class: 'launcher', type: 'button', 'aria-label': t.open, 'aria-expanded': 'false' });
    els.launcher.appendChild(mark('#fff', '#FFB400', 'mk'));
    var cx = ico('x', 26); cx.setAttribute('class', 'cx'); cx.setAttribute('stroke-width', '2.2');
    els.launcher.appendChild(cx);
    els.launcher.addEventListener('click', function () { toggle(); });

    // ----- en-tete -----
    els.av = el('span', { class: 'av', 'aria-hidden': 'true' }, [mark(color, '#FFB400', 'mk')]);
    els.title = el('b', { text: config.title });
    els.statusLine = el('small', { text: t.online });
    var titleBox = el('div', { class: 'ttl' }, [els.title, els.statusLine]);

    var langs = config.languages || [];
    if (langs.length > 1) {
      els.langBtn = el('button', { class: 'icon lang', type: 'button', 'aria-haspopup': 'true', 'aria-expanded': 'false' });
      els.langBtn.appendChild(ico('globe', 16));
      els.langCode = el('span', { text: lang.toUpperCase() });
      els.langBtn.appendChild(els.langCode);
      els.langBtn.addEventListener('click', function (e) { e.stopPropagation(); togglePop(els.langMenu, els.langBtn); });
      els.langMenu = el('div', { class: 'pop', role: 'menu' });
      langs.forEach(function (l) {
        var b = el('button', { class: 'item', type: 'button', role: 'menuitemradio', 'aria-checked': String(l.code === lang) }, [el('span', { text: l.name })]);
        b.dataset.code = l.code;
        b.addEventListener('click', function () { chooseLang(l.code); });
        els.langMenu.appendChild(b);
      });
    }

    els.menuBtn = el('button', { class: 'icon', type: 'button', 'aria-haspopup': 'true', 'aria-expanded': 'false', 'aria-label': t.menu });
    els.menuBtn.appendChild(ico('dots', 18));
    els.menuBtn.addEventListener('click', function (e) { e.stopPropagation(); togglePop(els.menu, els.menuBtn); });
    els.menu = el('div', { class: 'pop', role: 'menu' });
    els.miNew = menuItem('refresh', t.newChat, newConversation);
    els.miTalk = menuItem('send', t.talk, function () { closePops(); send(t.talkText); });
    els.menu.appendChild(els.miNew);
    els.menu.appendChild(els.miTalk);
    if (config.whatsapp) { els.miWa = menuItem('whatsapp', t.whatsapp, continueOnWhatsApp); els.menu.appendChild(els.miWa); }

    els.close = el('button', { class: 'icon', type: 'button', 'aria-label': t.close });
    els.close.appendChild(ico('x', 16));
    els.close.addEventListener('click', function () { toggle(false); });

    var head = el('div', { class: 'head' }, [els.av, titleBox, els.langBtn, els.menuBtn, els.close]);

    // ----- messages et saisie -----
    els.msgs = el('div', { class: 'msgs', role: 'log', 'aria-live': 'polite' });
    els.input = el('input', { type: 'text', placeholder: t.placeholder, maxlength: '2000', 'aria-label': t.placeholder, autocomplete: 'off', enterkeyhint: 'send' });
    els.sendBtn = el('button', { type: 'submit', class: 'round go', 'aria-label': t.send });
    els.sendBtn.appendChild(ico('send', 18));
    els.form = el('form', { class: 'send' }, [els.input]);

    var canListen = !!(config.voice && config.voice.listen && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    if (canListen) {
      els.micBtn = el('button', { type: 'button', class: 'round mic', 'aria-label': t.mic, title: t.mic });
      els.micBtn.appendChild(ico('mic', 18));
      els.micBtn.addEventListener('click', startRecording);
      els.form.appendChild(els.micBtn);

      els.rec = el('div', { class: 'rec' });
      els.recDot = el('span', { class: 'dot' });
      els.recTime = el('span', { class: 'tm', text: '0:00' });
      els.recWave = wave(14);
      els.recCancel = el('button', { type: 'button', class: 'round mic', 'aria-label': t.micCancel, title: t.micCancel });
      els.recCancel.appendChild(ico('x', 16));
      els.recCancel.addEventListener('click', function () { stopRecording(true); });
      els.recSend = el('button', { type: 'button', class: 'round go', 'aria-label': t.micSend, title: t.micSend });
      els.recSend.appendChild(ico('send', 18));
      els.recSend.addEventListener('click', function () { stopRecording(false); });
      [els.recDot, els.recTime, els.recWave, els.recCancel, els.recSend].forEach(function (n) { els.rec.appendChild(n); });
      els.form.appendChild(els.rec);
    }
    els.form.appendChild(els.sendBtn);
    els.form.addEventListener('submit', function (e) { e.preventDefault(); send(els.input.value); });

    var parts = [head, els.langMenu, els.menu, els.msgs, els.form];
    if (config.branding && config.brand) {
      els.powLink = el('a', { href: config.brand.url, target: '_blank', rel: 'noopener', text: config.brand.name });
      els.powText = document.createTextNode(t.powered + ' ');
      var pow = el('div', { class: 'pow' });
      pow.appendChild(els.powText);
      pow.appendChild(els.powLink);
      parts.push(pow);
    }

    els.panel = el('div', { class: 'panel', role: 'dialog', 'aria-label': config.title }, parts);
    applyDirection();
    els.panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { if (isPopOpen()) closePops(); else toggle(false); } });
    root.addEventListener('click', function () { closePops(); });

    root.appendChild(style);
    root.appendChild(els.panel);
    root.appendChild(els.launcher);
    document.body.appendChild(host);
  }

  function menuItem(icon, label, handler) {
    var b = el('button', { class: 'item', type: 'button', role: 'menuitem' });
    b.appendChild(ico(icon, 17));
    b.appendChild(el('span', { text: label }));
    b.addEventListener('click', function (e) { e.stopPropagation(); handler(); });
    return b;
  }

  function wave(n) {
    var w = el('span', { class: 'wave', 'aria-hidden': 'true' });
    for (var i = 0; i < n; i++) w.appendChild(el('i'));
    return w;
  }

  // ---------- menus ----------
  function isPopOpen() { return (els.menu && els.menu.classList.contains('show')) || (els.langMenu && els.langMenu.classList.contains('show')); }
  function closePops() {
    [els.menu, els.langMenu].forEach(function (p) { if (p) p.classList.remove('show'); });
    [els.menuBtn, els.langBtn].forEach(function (b) { if (b) b.setAttribute('aria-expanded', 'false'); });
  }
  function togglePop(pop, btn) {
    var was = pop.classList.contains('show');
    closePops();
    if (!was) { pop.classList.add('show'); btn.setAttribute('aria-expanded', 'true'); }
  }

  function applyDirection() {
    var langs = config.languages || [];
    var rtl = config.rtl;
    langs.forEach(function (l) { if (l.code === lang) rtl = l.rtl; });
    if (rtl) els.panel.setAttribute('dir', 'rtl'); else els.panel.removeAttribute('dir');
  }

  // ---------- choix de la langue ----------
  function chooseLang(code) {
    lang = code; saved.lang = code; persist();
    t = I18N[code] || I18N.fr;
    els.langCode.textContent = code.toUpperCase();
    Array.prototype.forEach.call(els.langMenu.children, function (b) { b.setAttribute('aria-checked', String(b.dataset.code === code)); });
    els.input.placeholder = t.placeholder; els.input.setAttribute('aria-label', t.placeholder);
    els.launcher.setAttribute('aria-label', t.open);
    els.close.setAttribute('aria-label', t.close);
    els.menuBtn.setAttribute('aria-label', t.menu);
    els.sendBtn.setAttribute('aria-label', t.send);
    if (els.micBtn) { els.micBtn.setAttribute('aria-label', t.mic); els.micBtn.title = t.mic; }
    setLabel(els.miNew, t.newChat); setLabel(els.miTalk, t.talk); if (els.miWa) setLabel(els.miWa, t.whatsapp);
    if (els.powText) els.powText.nodeValue = t.powered + ' ';
    if (!typingShown()) els.statusLine.textContent = t.online;
    applyDirection();
    closePops();
    els.input.focus();
  }
  function setLabel(item, text) { item.lastChild.textContent = text; }

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

  // La reponse « s'ecrit » : les caracteres apparaissent dans l'ordre de lecture, sur une duree qui suit la longueur.
  function reveal(bubble, done) {
    var nodes = [], walker = document.createTreeWalker(bubble, NodeFilter.SHOW_TEXT, null, false), n, total = 0;
    while ((n = walker.nextNode())) { nodes.push({ node: n, full: n.nodeValue }); total += n.nodeValue.length; }
    if (reduceMotion || total < 12 || document.hidden) { done(); return; }
    nodes.forEach(function (x) { x.node.nodeValue = ''; });
    var duration = Math.min(1700, Math.max(350, total * 9)), begin = null;
    function step(now) {
      if (begin === null) begin = now;
      var shown = Math.min(total, Math.round(((now - begin) / duration) * total)), left = shown;
      for (var i = 0; i < nodes.length; i++) {
        var take = Math.max(0, Math.min(nodes[i].full.length, left));
        nodes[i].node.nodeValue = nodes[i].full.slice(0, take);
        left -= take;
      }
      scroll();
      if (shown < total) requestAnimationFrame(step); else done();
    }
    requestAnimationFrame(step);
  }

  function clearChips() { var c = els.msgs.querySelector('.chips'); if (c) c.remove(); }
  function clearLast() { var l = els.msgs.querySelector('.row.last'); if (l) l.classList.remove('last'); }

  function addMessage(msg, opts) {
    opts = opts || {};
    var kind = msg.role === 'user' ? 'user' : (msg.role === 'agent' ? 'agent' : 'bot');
    var row = el('div', { class: 'row ' + kind });
    var bubble = el('div', { class: 'm' });
    if (kind === 'agent') bubble.appendChild(el('span', { class: 'who', text: t.agent }));
    renderText(bubble, msg.content);
    // Photos de produits jointes à la réponse : touchées, elles s'ouvrent en grand.
    if (msg.media && msg.media.length) {
      var gallery = el('div', { class: 'media' });
      msg.media.forEach(function (m) {
        var link = el('a', { class: 'ph', href: m.url, target: '_blank', rel: 'noopener noreferrer' });
        var img = el('img', { src: m.url, alt: m.name || '', loading: 'lazy' });
        img.addEventListener('load', scroll);
        link.appendChild(img);
        if (m.caption) link.appendChild(el('span', { text: m.caption }));
        gallery.appendChild(link);
      });
      bubble.appendChild(gallery);
    }
    if (msg.sources && msg.sources.length) {
      var src = el('div', { class: 'src' });
      msg.sources.slice(0, 3).forEach(function (s, i) {
        if (i) src.appendChild(document.createTextNode(' · '));
        src.appendChild(el('a', { href: s.url, target: '_blank', rel: 'noopener noreferrer', text: s.title || s.url }));
      });
      bubble.appendChild(src);
    }
    row.appendChild(bubble);
    clearChips();
    if (kind !== 'user') clearLast();
    els.msgs.appendChild(row);
    if (msg.id && msg.id > lastId) lastId = msg.id;
    if (kind === 'user') lastQuestion = msg.content;

    var finish = function () {
      if (kind !== 'user') { row.classList.add('last'); row.appendChild(tools(msg, row)); }
      if (opts.chips !== false && kind !== 'user' && msg.suggestions && msg.suggestions.length) chips(msg.suggestions);
      if (opts.after) opts.after(row);
      scroll();
    };
    if (opts.write && kind === 'bot') reveal(bubble, finish); else finish();
    scroll();

    return row;
  }

  // Outils sous une reponse : ecouter, copier, pouce leve, pouce baisse.
  function tools(msg, row) {
    var bar = el('div', { class: 'tools' });
    if (config.voice && config.voice.speak && msg.id) {
      var listen = el('button', { class: 'tool', type: 'button', 'aria-label': t.listen, title: t.listen }, [ico('listen', 15)]);
      listen.addEventListener('click', function () { listenTo(msg, listen); });
      bar.appendChild(listen);
      row._listen = listen;
    }
    var copy = el('button', { class: 'tool', type: 'button', 'aria-label': t.copy, title: t.copy }, [ico('copy', 15)]);
    copy.addEventListener('click', function () {
      copyText(msg.content, function () {
        copy.replaceChild(ico('check', 15), copy.firstChild); copy.classList.add('ok'); copy.title = t.copied;
        setTimeout(function () { copy.replaceChild(ico('copy', 15), copy.firstChild); copy.classList.remove('ok'); copy.title = t.copy; }, 1600);
      });
    });
    bar.appendChild(copy);

    if (msg.id && msg.role === 'assistant') {
      var up = el('button', { class: 'tool', type: 'button', 'aria-label': t.good, 'aria-pressed': 'false', title: t.good }, [ico('up', 15)]);
      var down = el('button', { class: 'tool', type: 'button', 'aria-label': t.bad, 'aria-pressed': 'false', title: t.bad }, [ico('down', 15)]);
      var rate = function (value, btn, other) {
        var on = !btn.classList.contains('on');
        btn.classList.toggle('on', on); btn.setAttribute('aria-pressed', String(on));
        other.classList.remove('on'); other.setAttribute('aria-pressed', 'false');
        if (conversation) request('POST', '/conversations/' + conversation + '/messages/' + msg.id + '/feedback', { value: on ? value : null }).catch(function () { /* l'avis est un confort */ });
        if (on && value === 'down') note(t.thanks);
      };
      up.addEventListener('click', function () { rate('up', up, down); });
      down.addEventListener('click', function () { rate('down', down, up); });
      bar.appendChild(up);
      bar.appendChild(down);
    }
    return bar;
  }

  function copyText(text, ok) {
    var done = function () { if (ok) ok(); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, fallback); return; }
    fallback();
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      root.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { /* copie impossible */ }
      root.removeChild(ta);
    }
  }

  function chips(list) {
    var box = el('div', { class: 'chips' });
    list.slice(0, 3).forEach(function (q, i) {
      var b = el('button', { type: 'button', text: q });
      b.style.animationDelay = (i * 70) + 'ms';
      b.addEventListener('click', function () { send(q); });
      box.appendChild(b);
    });
    els.msgs.appendChild(box);
  }

  function note(text) { els.msgs.appendChild(el('div', { class: 'note', text: text })); scroll(); }
  function scroll() { els.msgs.scrollTop = els.msgs.scrollHeight; }
  function typingShown() { return !!els.msgs.querySelector('.typing'); }
  function typing(on) {
    var existing = els.msgs.querySelector('.typing');
    if (on && !existing) {
      clearChips();
      els.msgs.appendChild(el('div', { class: 'typing' }, [el('i'), el('i'), el('i')]));
      els.av.classList.add('talk'); els.statusLine.textContent = t.writing; scroll();
    }
    if (!on && existing) { existing.remove(); els.av.classList.remove('talk'); els.statusLine.textContent = t.online; }
  }

  // ---------- comportement ----------
  function toggle(force) {
    opened = typeof force === 'boolean' ? force : !opened;
    els.panel.classList.toggle('open', opened);
    els.launcher.classList.toggle('is-open', opened);
    els.launcher.setAttribute('aria-expanded', String(opened));
    closePops();
    if (opened) { hideTeaser(true); start(); setTimeout(function () { els.input.focus(); }, 380); schedulePoll(); }
    else { stopPoll(); stopRecording(true); stopAudio(); }
  }

  // Bulle d'accroche : une fois par session, quelques secondes apres le chargement, si le widget est reste ferme.
  function scheduleTeaser() {
    try { if (sessionStorage.getItem(STORE + ':teaser')) return; } catch (e) { return; }
    teaserTimer = setTimeout(function () {
      if (opened || !config.welcome) return;
      try { sessionStorage.setItem(STORE + ':teaser', '1'); } catch (e) { /* ignore */ }
      var text = String(config.welcome);
      if (text.length > 110) text = text.slice(0, 107).replace(/\s+\S*$/, '') + '…';
      els.teaser = el('div', { class: 'teaser', role: 'status' }, [document.createTextNode(text)]);
      var x = el('button', { class: 'tx', type: 'button', 'aria-label': t.close }, [ico('x', 12)]);
      x.addEventListener('click', function (e) { e.stopPropagation(); hideTeaser(true); });
      els.teaser.appendChild(x);
      els.teaser.addEventListener('click', function () { toggle(true); });
      root.appendChild(els.teaser);
      setTimeout(function () { hideTeaser(false); }, 14000);
    }, 3500);
  }
  function hideTeaser() { clearTimeout(teaserTimer); if (els.teaser) { els.teaser.remove(); els.teaser = null; } }

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
        addMessage({ role: 'assistant', content: config.welcome, suggestions: config.suggested || [] }, { write: true });
      } else {
        data.messages.forEach(function (m, i) { addMessage(m, { chips: i === data.messages.length - 1 }); });
      }
      if (status === 'needs_human' || status === 'human') schedulePoll();
    }).catch(function () { note(t.error); }).then(function () { busy = false; });
  }

  // Une nouvelle conversation ferme l'ancienne, cote serveur, puis repart d'un ecran vide.
  function newConversation() {
    closePops();
    if (busy) return;
    var old = conversation;
    conversation = null; lastId = 0; status = 'bot'; lastQuestion = '';
    stopPoll(); stopAudio();
    els.msgs.innerHTML = '';
    var next = function () { open({}); };
    if (old) request('POST', '/conversations/' + old + '/close').then(next, next); else next();
  }

  function whatsappLink() {
    var text = lastQuestion || t.waHello;
    return 'https://wa.me/' + encodeURIComponent(config.whatsapp) + '?text=' + encodeURIComponent(text);
  }
  function continueOnWhatsApp() { closePops(); window.open(whatsappLink(), '_blank', 'noopener'); }

  function handoffNote() {
    note(t.human);
    if (config.whatsapp) {
      var a = el('a', { class: 'cta', href: whatsappLink(), target: '_blank', rel: 'noopener' }, [ico('whatsapp', 17), el('span', { text: t.whatsapp })]);
      els.msgs.appendChild(a);
      scroll();
    }
  }

  function afterAnswer(data, startedAt) {
    // Un court delai minimum : une reponse instantanee parait suspecte, et l'indicateur evite un « saut ».
    var wait = Math.max(0, 650 - (Date.now() - startedAt));
    return new Promise(function (resolve) { setTimeout(function () { resolve(data); }, wait); }).then(function (d) {
      typing(false);
      var wasHuman = status === 'needs_human' || status === 'human';
      status = d.status;
      var handed = !d.message || (!wasHuman && (status === 'needs_human' || status === 'human'));
      if (d.message) {
        addMessage(d.message, { write: true, after: function (row) { if (d.audio) playData(d.audio, row); } });
      }
      if (handed) handoffNote();
      schedulePoll();
    });
  }

  function send(text) {
    text = String(text || '').trim();
    if (!text || !conversation || busy) return;
    busy = true;
    els.input.value = '';
    els.sendBtn.disabled = true;
    addMessage({ role: 'user', content: text });
    typing(true);
    var payload = { content: text };
    if ((config.languages || []).length > 1) payload.lang = lang;

    var shownAt = Date.now();
    request('POST', '/conversations/' + conversation + '/messages', payload).then(function (data) {
      return afterAnswer(data, shownAt);
    }).catch(function () {
      typing(false);
      note(t.error);
    }).then(function () { busy = false; els.sendBtn.disabled = false; els.input.focus(); });
  }

  // ---------- voix : enregistrer, envoyer, ecouter ----------
  function pickMime() {
    var options = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm'];
    for (var i = 0; i < options.length; i++) { if (window.MediaRecorder.isTypeSupported && window.MediaRecorder.isTypeSupported(options[i])) return options[i]; }
    return '';
  }

  function startRecording() {
    if (recorder || busy || !conversation) return;
    stopAudio();
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var mime = pickMime(), chunks = [];
      try { recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined); } catch (e) { stream.getTracks().forEach(function (tr) { tr.stop(); }); note(t.voiceFail); return; }
      recCancelled = false;
      recorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      recorder.onstop = function () {
        stream.getTracks().forEach(function (tr) { tr.stop(); });
        var type = (recorder && recorder.mimeType) || mime || 'audio/webm';
        recorder = null; clearInterval(recTimer);
        els.rec.classList.remove('on');
        if (recCancelled || !chunks.length) return;
        sendVoice(new Blob(chunks, { type: type }));
      };
      recorder.start();
      recStart = Date.now();
      els.rec.classList.add('on');
      els.recTime.textContent = '0:00';
      recTimer = setInterval(function () {
        var s = Math.floor((Date.now() - recStart) / 1000);
        els.recTime.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
        if (s >= 60) stopRecording(false);
      }, 250);
    }).catch(function () { note(t.micDenied); });
  }

  function stopRecording(cancel) {
    if (!recorder) return;
    recCancelled = !!cancel;
    try { recorder.stop(); } catch (e) { recorder = null; els.rec.classList.remove('on'); }
  }

  function extension(type) {
    if (/ogg/.test(type)) return 'ogg';
    if (/mp4|m4a|aac/.test(type)) return 'm4a';
    return 'webm';
  }

  function sendVoice(blob) {
    if (busy || !conversation) return;
    busy = true;
    els.sendBtn.disabled = true;

    // Bulle du vocal, avec l'onde ; la transcription s'y ajoute des qu'elle arrive.
    var row = el('div', { class: 'row user' });
    var bubble = el('div', { class: 'm' });
    var box = el('span', { class: 'voice' }, [ico('mic', 16), wave(12)]);
    var tr = el('span', { class: 'vt', text: t.transcribing });
    bubble.appendChild(box); bubble.appendChild(tr);
    row.appendChild(bubble);
    clearChips(); els.msgs.appendChild(row); scroll();
    typing(true);

    var form = new FormData();
    form.append('audio', blob, 'vocal.' + extension(blob.type));
    if ((config.languages || []).length > 1) form.append('lang', lang);
    var shownAt = Date.now();

    upload('/conversations/' + conversation + '/voice', form).then(function (data) {
      tr.textContent = '« ' + data.transcript + ' »';
      lastQuestion = data.transcript;
      box.querySelector('.wave').style.display = 'none';
      return afterAnswer(data, shownAt);
    }).catch(function (err) {
      typing(false);
      row.remove();
      note(err && err.data && err.data.message ? err.data.message : t.voiceFail);
    }).then(function () { busy = false; els.sendBtn.disabled = false; });
  }

  function stopAudio() {
    if (player) { try { player.pause(); } catch (e) { /* deja arrete */ } player = null; }
    Array.prototype.forEach.call(els.msgs ? els.msgs.querySelectorAll('.tool.on.listening') : [], function (b) { b.classList.remove('on', 'listening'); });
  }

  function playData(uri, row) {
    stopAudio();
    player = new Audio(uri);
    var button = row && row._listen;
    if (button) { button.classList.add('on', 'listening'); }
    player.onended = function () { player = null; if (button) button.classList.remove('on', 'listening'); };
    player.play().catch(function () { /* lecture automatique refusee : le bouton « Ecouter » reste disponible */ if (button) button.classList.remove('on', 'listening'); });
  }

  function listenTo(msg, button) {
    if (button.classList.contains('listening')) { stopAudio(); return; }
    if (!conversation) return;
    var row = button.parentNode.parentNode;
    button.disabled = true;
    request('POST', '/conversations/' + conversation + '/speak', { message_id: msg.id }).then(function (data) {
      playData(data.audio, row);
    }).catch(function (err) {
      note(err && err.data && err.data.message ? err.data.message : t.voiceFail);
    }).then(function () { button.disabled = false; });
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
      data.messages.forEach(function (m) { addMessage(m, { chips: false }); });
    }).catch(function () { /* on reessaie au prochain tour */ }).then(schedulePoll);
  }
})();
