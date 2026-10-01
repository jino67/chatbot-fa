/*
 * Cerveau de la démonstration interactive (appareil pliable de la page d'accueil). Entièrement local et déterministe :
 * aucun appel réseau, aucune IA. Il comprend une trentaine de tournures (prix, commande, livraison, horaires,
 * paiement, demande d'une personne, tentative de détournement) en français, et l'essentiel en anglais.
 * La boutique est fictive. Module pur : il ne touche jamais au DOM (testable avec Node).
 */

export const SHOP = { name: 'Boutique Awa', owner: 'Awa' };

export const CATALOG = [
    { id: 'robe', article: 'La', label: 'robe en wax', plural: 'robes en wax', price: 18000, re: /\b(?:robes?|wax|pagnes?)\b/ },
    { id: 'boubou', article: 'Le', label: 'boubou brodé', plural: 'boubous brodés', price: 35000, re: /\b(?:boubous?|brodes?)\b/ },
    { id: 'sac', article: 'Le', label: 'sac en cuir', plural: 'sacs en cuir', price: 22000, re: /\b(?:sacs?|cuir|sacoches?|bags?)\b/ },
    { id: 'foulard', article: 'Le', label: 'foulard', plural: 'foulards', price: 4500, re: /\b(?:foulards?|echarpes?|scarf|scarves)\b/ },
];

export const CITIES = [
    { id: 'ouaga', label: 'Ouagadougou', fee: 1000, delay: '24 h', re: /\b(?:ouaga|ouagadougou)\b/ },
    { id: 'bobo', label: 'Bobo-Dioulasso', fee: 2000, delay: '48 h', re: /\bbobo(?:[- ]dioulasso)?\b/ },
];

export const FREE_FROM = 25000;

const NARROW = ' ';
const NBSP = ' ';

export const money = (n) => `${String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, NARROW)}${NBSP}FCFA`;

const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[’`]/g, "'").replace(/\s+/g, ' ').trim();

/** Mots que l'assistant connaît : un mot du client qui s'en écarte d'une lettre (deux pour les longs mots) est corrigé. */
const VOCAB = [
    'boutique', 'magasin', 'informations', 'information', 'renseignements', 'presentation', 'livraison', 'livrez', 'livrer', 'horaires', 'ouverture',
    'commande', 'commander', 'catalogue', 'produits', 'articles', 'paiement', 'payer', 'adresse', 'combien', 'boubou', 'boubous', 'foulard', 'foulards',
    'sacoche', 'echarpe', 'disponible', 'dimanche', 'samedi', 'confirmer', 'conseiller', 'responsable', 'reclamation', 'remboursement', 'merci',
    'bonjour', 'bonsoir', 'ouvert', 'ouverts', 'tarifs', 'espèces', 'especes', 'delivery', 'shipping', 'payment', 'address', 'opening', 'hello', 'thanks',
];

function distance(a, b) {
    const row = Array.from({ length: b.length + 1 }, (_, j) => j);
    for (let i = 1; i <= a.length; i++) {
        let prev = row[0];
        row[0] = i;
        for (let j = 1; j <= b.length; j++) {
            const keep = row[j];
            row[j] = Math.min(row[j] + 1, row[j - 1] + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
            prev = keep;
        }
    }

    return row[b.length];
}

function correct(t) {
    return t.replace(/[a-z]{5,}/g, (word) => {
        if (VOCAB.includes(word)) return word;
        let best = null;
        for (const known of VOCAB) {
            if (known.length < 5 || Math.abs(known.length - word.length) > 2) continue;
            const d = distance(word, known);
            if (d <= (known.length >= 8 ? 2 : 1) && (best === null || d < best.d)) best = { known, d };
        }

        return best ? best.known : word;
    });
}

const NUMBERS = { un: 1, une: 1, deux: 2, trois: 3, quatre: 4, cinq: 5, six: 6, sept: 7, huit: 8, neuf: 9, dix: 10, one: 1, two: 2, three: 3, four: 4, five: 5 };

const RE = {
    injection: /ignore[rz]?\b.*\b(?:instruction|regle|consigne|rules?)|oublie[rz]?\b.*\b(?:instruction|regle|consigne)|(?:system|systeme)\s*prompt|prompt\s*systeme|tu es (?:maintenant|desormais)|reveal.*(?:prompt|instruction)|revele.*(?:instruction|consigne|prompt)|montre.*(?:tes|ton).*(?:instruction|consigne|prompt)|jailbreak|\bdan mode\b/,
    human: /(?:parler|discuter|joindre|contacter|passer|speak|talk)\b.{0,25}\b(?:awa|personne|humain|human|conseill|responsable|patron|gerant|quelqu|someone|manager|owner)|\b(?:un|une)\s+(?:humain|personne|conseiller|conseillere|vrai)\b|\bhumain\b|reclamation|plainte|complaint|arnaque|urgent|rembours|refund|pas content|mecontent|inadmissible|scandale/,
    yes: /^(?:oui|ouais|ok|okay|d'accord|dac|yes|yep|parfait|c'est bon|vas-y|allez|confirm\w*|valide\w*|je confirme|je valide|go)\b|\b(?:oui|yes)\b.{0,20}\b(?:confirm|valide|prevenir|preveni|d'accord)/,
    no: /^(?:non|no|nope|pas maintenant|plus tard|laisse|laissez|annul\w*|cancel)\b|non merci|no thanks/,
    modify: /modifi\w*|change\w*|corrig\w*|plutot|edit/,
    orderVerb: /\bje (?:prends|veux|voudrais|souhaite|commande|vais prendre|achete)\b|\bcommand\w*|\bacheter\b|\bj'achete\b|\bil me faut\b|\bajoute\w*\b|\bi(?:'d| would)? (?:like|want|take)\b|\bi'll take\b|\border\b|\bje le prends\b|\bje la prends\b|\bje les prends\b/,
    price: /\bprix\b|combien|\bcoute\w*|\btarifs?\b|how much|\bprice\b|\bcost\b/,
    catalog: /catalogue|produits|articles|vendez|proposez|what do you sell|what do you have|que vendez|qu'avez-vous|avez-vous quoi|selection/,
    delivery: /livr\w*|expedi\w*|\benvoi\w*|deliver\w*|shipping|\bship\b|frais de port/,
    hours: /horaires?|\bouvert\w*|ouverture|\bferme\w*|\bheures?\b|dimanche|samedi|lundi|opening|\bopen\b|hours|what time|jusqu'a quelle heure/,
    payment: /paie\w*|payer|orange|moov|mobile money|especes|cash|carte|\bpay\b|payment/,
    about: /\b(?:infos?|informations?|renseignements?|presentation|presentez|presente|decrivez|about|tell me about|who are you|qui etes-vous|qui etes vous|c'est quoi)\b|\b(?:votre|la) (?:boutique|magasin|entreprise)\b|\b(?:your|the) (?:shop|store|business)\b/,
    place: /adresse|\bsitue\w*|ou etes-vous|ou se trouve|localisation|\bwhere\b|address|boutique physique/,
    thanks: /\bmerci\b|thanks|thank you|\bcool\b|super|genial|nickel/,
    bye: /au revoir|a bientot|bonne soiree|bonne journee|bye|\bciao\b|a demain/,
    greeting: /\b(?:bonjour|bonsoir|salut|coucou|hello|hi|hey|salam|bjr|slt|bsr)\b/,
    english: /\b(?:hello|hi|hey|price|how much|delivery|deliver|open|hours|order|thanks|thank you|please|do you|what|where|can i|i want|i'd like|i'll take|shipping|address|payment|tell me|about|your shop|your store|who are you)\b/,
    frenchHint: /\b(?:bonjour|bonsoir|salut|je|vous|est-ce|combien|livr|merci|oui|non|prix|horaires|commande|voudrais|veux)\b/,
};

export function createState() {
    return { lang: 'fr', order: null, awaiting: null, city: null, handoff: false, lastProduct: null };
}

const detectLang = (t, current) => {
    if (RE.frenchHint.test(t)) return 'fr';
    if (RE.english.test(t)) return 'en';

    return current;
};

/** Phrases de l'assistant, par langue. Les prix sont toujours ceux du catalogue. */
const TEXT = {
    fr: {
        hello: () => `Bonjour ! Je suis l'assistant de la ${SHOP.name}. Je peux vous donner les prix, les horaires ou la livraison, et prendre votre commande.`,
        thanks: () => 'Avec plaisir ! Autre chose pour votre commande ?',
        bye: () => `À bientôt chez ${SHOP.name} !`,
        refuse: () => `Je reste l'assistant de la ${SHOP.name} : je ne peux pas changer mes règles, mais je peux vous aider pour les produits, les prix et la livraison.`,
        hours: () => 'Nous sommes ouverts **du lundi au samedi, de 8 h à 19 h**, et **le dimanche de 9 h à 13 h**.',
        payment: () => 'Vous pouvez payer par **Orange Money**, **Moov Money**, ou **en espèces à la livraison**.',
        place: () => `La ${SHOP.name} se trouve à Ouagadougou, secteur 15. Nous livrons aussi à Bobo-Dioulasso.`,
        about: () => `La **${SHOP.name}** vend des vêtements et accessoires africains : robes en wax, boubous brodés, sacs en cuir et foulards.\nNous sommes ouverts **du lundi au samedi de 8 h à 19 h** et **le dimanche de 9 h à 13 h**, et nous livrons à Ouagadougou et à Bobo-Dioulasso. Paiement par Orange Money, Moov Money ou en espèces à la livraison.`,
        catalog: () => `Voici nos articles :\n${CATALOG.map((p) => `• ${cap(p.label)} : **${money(p.price)}**`).join('\n')}`,
        price: (p) => `${p.article} ${p.label} coûte **${money(p.price)}**.`,
        deliveryCity: (c) => `Oui, à ${c.label} : **${money(c.fee)}**, livré sous ${c.delay}. Gratuit dès ${money(FREE_FROM)} d'achat.`,
        deliveryAll: () => `Nous livrons à **Ouagadougou** (${money(1000)}, sous 24 h) et à **Bobo-Dioulasso** (${money(2000)}, sous 48 h). Gratuit dès ${money(FREE_FROM)} d'achat.`,
        deliveryOther: () => 'Pour le moment, nous livrons à Ouagadougou et à Bobo-Dioulasso. Pour une autre ville, Awa peut vous proposer une solution : je la préviens ?',
        askCity: () => `Livraison : ${money(1000)} à Ouagadougou, ${money(2000)} à Bobo-Dioulasso. Dans quelle ville êtes-vous ?`,
        askProduct: () => 'Avec plaisir ! Quel article souhaitez-vous, et en quelle quantité ?',
        human: () => `Bien sûr, je préviens **${SHOP.owner}** : elle vous répond ici.`,
        cancelled: () => 'D\'accord, j\'annule cette commande. Dites-moi ce que vous voulez modifier.',
        modify: () => 'Pas de souci. Dites-moi l\'article et la quantité souhaités.',
        noThanks: () => 'D\'accord. Je reste disponible si vous avez une question.',
        fallback: () => `Je n'ai pas cette information pour le moment. Voulez-vous que je prévienne ${SHOP.owner} ?`,
        confirmed: () => `Merci ! Votre commande est enregistrée. **${SHOP.owner}** la valide et vous confirme le paiement (Orange Money, Moov Money ou espèces à la livraison) et l'heure de livraison.`,
        tooMany: () => `Pour une quantité importante, ${SHOP.owner} peut vous faire un prix : je la préviens ?`,
        lines: (lines, order) => (lines.length === 1
            ? `${lines[0].qty} ${lines[0].qty > 1 ? lines[0].product.plural : lines[0].product.label} × ${money(lines[0].product.price)} = **${money(order.subtotal)}**.`
            : `${lines.map((l) => `• ${l.qty} ${l.qty > 1 ? l.product.plural : l.product.label} : ${money(l.qty * l.product.price)}`).join('\n')}\nTotal des articles : **${money(order.subtotal)}**.`),
        proposal: (lines, order) => {
            const delivery = order.fee === 0
                ? `Livraison offerte, car la commande dépasse ${money(FREE_FROM)}.`
                : `Livraison à ${order.city.label} : ${money(order.fee)} (sous ${order.city.delay}). **Total : ${money(order.total)}.**`;

            return `${TEXT.fr.lines(lines, order)} ${delivery} Je confirme ?`;
        },
        chips: {
            start: ['Vos horaires ?', 'Le prix d\'un boubou', 'Livrez-vous à Bobo ?'],
            afterPrice: ['Je le prends', 'Vos autres articles ?', 'Livraison ?'],
            confirm: ['Confirmer', 'Modifier'],
            city: ['Ouagadougou', 'Bobo-Dioulasso'],
            prevenir: ['Oui, prévenir Awa', 'Non merci'],
            more: ['Je veux commander', 'Parler à Awa'],
            about: ['Voir les prix', 'Livraison ?', 'Je veux commander'],
        },
        sources: { catalog: 'Catalogue et prix', delivery: 'Livraison', hours: 'Horaires', payment: 'Paiement', place: 'Boutique', about: 'Présentation de la boutique' },
    },
    en: {
        hello: () => `Hello! I'm the ${SHOP.name} assistant. I can give you prices, opening hours and delivery details, and take your order.`,
        thanks: () => 'My pleasure! Anything else for your order?',
        bye: () => `See you soon at ${SHOP.name}!`,
        refuse: () => `I'm ${SHOP.name}'s assistant, so I can't change my rules, but I'm happy to help with products, prices and delivery.`,
        hours: () => 'We are open **Monday to Saturday, 8 am to 7 pm**, and **Sunday 9 am to 1 pm**.',
        payment: () => 'You can pay with **Orange Money**, **Moov Money**, or **cash on delivery**.',
        place: () => `${SHOP.name} is in Ouagadougou, sector 15. We also deliver to Bobo-Dioulasso.`,
        about: () => `**${SHOP.name}** sells African clothing and accessories: wax dresses, embroidered boubous, leather bags and scarves.\nWe are open **Monday to Saturday, 8 am to 7 pm** and **Sunday 9 am to 1 pm**, and we deliver to Ouagadougou and Bobo-Dioulasso. You can pay with Orange Money, Moov Money or cash on delivery.`,
        catalog: () => `Here is what we sell:\n${CATALOG.map((p) => `• ${cap(p.label)}: **${money(p.price)}**`).join('\n')}`,
        price: (p) => `The ${p.label} costs **${money(p.price)}**.`,
        deliveryCity: (c) => `Yes, we deliver to ${c.label}: **${money(c.fee)}**, within ${c.delay}. Free from ${money(FREE_FROM)}.`,
        deliveryAll: () => `We deliver to **Ouagadougou** (${money(1000)}, within 24 h) and **Bobo-Dioulasso** (${money(2000)}, within 48 h). Free from ${money(FREE_FROM)}.`,
        deliveryOther: () => `For now we deliver to Ouagadougou and Bobo-Dioulasso. For another city, ${SHOP.owner} can suggest something: shall I let her know?`,
        askCity: () => `Delivery: ${money(1000)} in Ouagadougou, ${money(2000)} in Bobo-Dioulasso. Which city are you in?`,
        askProduct: () => 'Happy to help! Which item would you like, and how many?',
        human: () => `Of course, I'm letting **${SHOP.owner}** know: she will reply here.`,
        cancelled: () => 'OK, I cancelled this order. Tell me what you would like to change.',
        modify: () => 'No problem. Tell me the item and quantity you want.',
        noThanks: () => "OK. I'm here if you have any question.",
        fallback: () => `I don't have that information right now. Would you like me to let ${SHOP.owner} know?`,
        confirmed: () => `Thank you! Your order is recorded. **${SHOP.owner}** will validate it and confirm payment and delivery time.`,
        tooMany: () => `For a large quantity, ${SHOP.owner} can offer a price: shall I let her know?`,
        lines: (lines, order) => (lines.length === 1
            ? `${lines[0].qty} ${lines[0].qty > 1 ? lines[0].product.plural : lines[0].product.label} × ${money(lines[0].product.price)} = **${money(order.subtotal)}**.`
            : `${lines.map((l) => `• ${l.qty} ${l.qty > 1 ? l.product.plural : l.product.label}: ${money(l.qty * l.product.price)}`).join('\n')}\nItems total: **${money(order.subtotal)}**.`),
        proposal: (lines, order) => {
            const delivery = order.fee === 0
                ? `Free delivery, since the order is above ${money(FREE_FROM)}.`
                : `Delivery to ${order.city.label}: ${money(order.fee)} (within ${order.city.delay}). **Total: ${money(order.total)}.**`;

            return `${TEXT.en.lines(lines, order)} ${delivery} Shall I confirm?`;
        },
        chips: {
            start: ['Opening hours?', 'Price of a boubou', 'Do you deliver to Bobo?'],
            afterPrice: ['I\'ll take it', 'Other items?', 'Delivery?'],
            confirm: ['Confirm', 'Change'],
            city: ['Ouagadougou', 'Bobo-Dioulasso'],
            prevenir: ['Yes, tell Awa', 'No thanks'],
            more: ['I want to order', 'Talk to Awa'],
            about: ['See prices', 'Delivery?', 'I want to order'],
        },
        sources: { catalog: 'Catalogue and prices', delivery: 'Delivery', hours: 'Opening hours', payment: 'Payment', place: 'Shop', about: 'Shop presentation' },
    },
};

const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

const findProduct = (t) => CATALOG.find((p) => p.re.test(t)) ?? null;

const findCity = (t) => CITIES.find((c) => c.re.test(t)) ?? null;

/** Articles et quantités de la phrase : « 2 boubous et un foulard ». */
function parseLines(t) {
    const lines = [];

    for (const clause of t.split(/\bet\b|\band\b|,|\+|&|;/)) {
        const product = findProduct(clause);
        if (!product) continue;

        let qty = 1;
        const digits = clause.match(/\b(\d{1,3})\b/);
        if (digits) {
            qty = Number(digits[1]);
        } else {
            const word = clause.split(/\s+/).find((w) => NUMBERS[w] !== undefined);
            if (word) qty = NUMBERS[word];
        }

        const known = lines.find((l) => l.product.id === product.id);
        if (known) known.qty += qty;
        else lines.push({ product, qty });
    }

    return lines;
}

function buildOrder(lines, city) {
    const subtotal = lines.reduce((sum, l) => sum + l.qty * l.product.price, 0);
    const free = subtotal >= FREE_FROM;
    const fee = free ? 0 : (city?.fee ?? null);

    return { lines, subtotal, city, fee, total: subtotal + (fee ?? 0), free };
}

const orderTitle = (order, L) => order.lines.map((l) => `${l.qty} ${l.qty > 1 ? l.product.plural : l.product.label}`).join(L === 'fr' ? ' et ' : ' and ');

/**
 * Répond à un message du client.
 *
 * @returns {{replies: {text:string, chips?:string[], source?:string}[], leads: object[], handoff: boolean, silent: boolean, lang: string}}
 */
export function answer(state, raw) {
    const text = String(raw ?? '').trim().slice(0, 300);
    const t = correct(norm(text));
    state.lang = detectLang(t, state.lang);
    const X = TEXT[state.lang];
    const out = { replies: [], leads: [], handoff: false, silent: false, lang: state.lang };

    // Une personne a pris la main : l'assistant se tait jusqu'à ce qu'on lui rende la conversation.
    if (state.handoff) {
        out.silent = true;

        return out;
    }

    const say = (message, source) => out.replies.push({ ...message, source: source ? X.sources[source] : undefined });
    const handoff = (reason) => {
        state.handoff = true;
        state.awaiting = null;
        state.order = null;
        out.handoff = true;
        out.leads.push({ kind: 'human', title: state.lang === 'fr' ? 'Demande une personne' : 'Asks for a person', detail: reason || text });
        say({ text: X.human() });
    };

    if (RE.injection.test(t)) {
        say({ text: X.refuse(), chips: X.chips.start });

        return out;
    }

    if (RE.human.test(t)) {
        handoff(text);

        return out;
    }

    // Réponses aux questions posées juste avant (confirmer, ville, prévenir).
    if (state.awaiting === 'prevenir') {
        if (RE.yes.test(t)) {
            handoff(state.lastQuestion || text);

            return out;
        }
        if (RE.no.test(t)) {
            state.awaiting = null;
            say({ text: X.noThanks(), chips: X.chips.start });

            return out;
        }
    }

    if (state.awaiting === 'confirm' && state.order) {
        if (RE.yes.test(t)) {
            const order = state.order;
            state.awaiting = null;
            state.order = null;
            out.leads.push({
                kind: 'order',
                title: `${orderTitle(order, state.lang)} : ${money(order.total)}`,
                detail: `${order.lines.map((l) => `${l.qty} × ${money(l.product.price)}`).join(' + ')}${order.fee === 0 ? ', livraison offerte' : `, livraison ${money(order.fee)}`}${order.city ? ` à ${order.city.label}` : ''}.`,
                amount: order.total,
            });
            say({ text: X.confirmed() });

            return out;
        }
        if (RE.no.test(t)) {
            state.awaiting = null;
            state.order = null;
            say({ text: X.cancelled(), chips: X.chips.more });

            return out;
        }
        if (RE.modify.test(t) && !parseLines(t).length) {
            state.awaiting = null;
            state.order = null;
            say({ text: X.modify() });

            return out;
        }
    }

    const city = findCity(t);
    if (city) state.city = city.id;
    const cityObj = CITIES.find((c) => c.id === state.city) ?? null;

    if (state.awaiting === 'city' && city && state.order) {
        state.order = buildOrder(state.order.lines, city);
        state.awaiting = 'confirm';
        say({ text: X.proposal(state.order.lines, state.order), chips: X.chips.confirm }, 'catalog');

        return out;
    }

    // Commande : verbe d'achat + article, ou simplement « 2 foulards ».
    const lines = parseLines(t);
    if (lines.length && (RE.orderVerb.test(t) || (/\d/.test(t) || Object.keys(NUMBERS).some((w) => new RegExp(`\\b${w}\\b`).test(t))) && !RE.price.test(t))) {
        if (lines.some((l) => l.qty > 20)) {
            state.awaiting = 'prevenir';
            state.lastQuestion = text;
            say({ text: X.tooMany(), chips: X.chips.prevenir });

            return out;
        }

        state.order = buildOrder(lines, cityObj);
        state.lastProduct = lines[0].product.id;

        if (state.order.fee === null) {
            state.awaiting = 'city';
            say({ text: `${X.lines(lines, state.order)}\n${X.askCity()}`, chips: X.chips.city }, 'catalog');
        } else {
            state.awaiting = 'confirm';
            say({ text: X.proposal(lines, state.order), chips: X.chips.confirm }, 'catalog');
        }

        return out;
    }

    // « Je le prends » après un prix : l'article dont on vient de parler.
    if (RE.orderVerb.test(t) && state.lastProduct) {
        const product = CATALOG.find((p) => p.id === state.lastProduct);
        state.order = buildOrder([{ product, qty: 1 }], cityObj);

        if (state.order.fee === null) {
            state.awaiting = 'city';
            say({ text: `${product.label} : **${money(product.price)}**.\n${X.askCity()}`, chips: X.chips.city }, 'catalog');
        } else {
            state.awaiting = 'confirm';
            say({ text: X.proposal(state.order.lines, state.order), chips: X.chips.confirm }, 'catalog');
        }

        return out;
    }

    if (RE.orderVerb.test(t)) {
        say({ text: X.askProduct(), chips: CATALOG.map((p) => cap(p.label)) });

        return out;
    }

    if (RE.delivery.test(t)) {
        const other = t.match(/(?:livr\w*|expedi\w*|envoi\w*|deliver\w*)[^.?!]*?\b(?:a|au|en|vers|to)\s+([a-z' -]{3,})/);
        const named = other?.[1]?.trim().split(' ')[0];
        const knownWord = !named || ['domicile', 'la', 'le', 'mon', 'moi', 'vous', 'nous', 'ouaga', 'bobo', 'ouagadougou', 'chez', 'toute', 'tous', 'the', 'my', 'me', 'home'].includes(named);

        if (city) {
            say({ text: X.deliveryCity(city) }, 'delivery');
        } else if (!knownWord) {
            state.awaiting = 'prevenir';
            state.lastQuestion = text;
            say({ text: X.deliveryOther(), chips: X.chips.prevenir }, 'delivery');
        } else {
            say({ text: X.deliveryAll(), chips: X.chips.city }, 'delivery');
        }

        return out;
    }

    const product = findProduct(t);
    if (product && (RE.price.test(t) || !RE.catalog.test(t))) {
        state.lastProduct = product.id;
        say({ text: X.price(product), chips: X.chips.afterPrice }, 'catalog');

        return out;
    }

    if (RE.price.test(t) || RE.catalog.test(t)) {
        say({ text: X.catalog(), chips: X.chips.more }, 'catalog');

        return out;
    }

    if (RE.hours.test(t)) {
        say({ text: X.hours(), chips: X.chips.more }, 'hours');

        return out;
    }

    if (RE.payment.test(t)) {
        say({ text: X.payment(), chips: X.chips.more }, 'payment');

        return out;
    }

    if (RE.place.test(t)) {
        say({ text: X.place() }, 'place');

        return out;
    }

    if (RE.about.test(t)) {
        say({ text: X.about(), chips: X.chips.about }, 'about');

        return out;
    }

    if (RE.thanks.test(t)) {
        say({ text: X.thanks(), chips: X.chips.more });

        return out;
    }

    if (RE.bye.test(t)) {
        say({ text: X.bye() });

        return out;
    }

    if (RE.greeting.test(t) || t.length < 3) {
        say({ text: X.hello(), chips: X.chips.start });

        return out;
    }

    state.awaiting = 'prevenir';
    state.lastQuestion = text;
    say({ text: X.fallback(), chips: X.chips.prevenir });

    return out;
}

/** Phrase de l'assistant quand une personne lui rend la conversation. */
export function resumeText(lang = 'fr') {
    return lang === 'fr'
        ? `Je reprends la conversation : ${SHOP.owner} vous a répondu. Je reste là pour la suite.`
        : `I'm back on the conversation: ${SHOP.owner} has replied. I'm here for the rest.`;
}

/** Aperçu de la première question qui reviendra dans la story (voir index.js). */
export const STORY = [
    { user: 'Bonsoir, vous livrez à Bobo ?' },
    { user: 'Je prends 2 boubous brodés.' },
    { user: 'Oui, je confirme' },
    { user: 'Je veux parler à Awa' },
    { owner: 'Bonsoir Fatou, c\'est Awa ! Je vous appelle dans deux minutes.' },
];
