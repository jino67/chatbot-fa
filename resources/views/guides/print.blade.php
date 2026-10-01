{{--
    Mise en page PDF d'un guide (A4), rendue en HTML puis imprimée par Chrome ou Edge sans interface (`php artisan guides:build`).
    Document autonome : pas de Tailwind, les polices viennent de resources/guides/fonts (chemins `file:///` passés par la commande).
    Données : $guide (titre, sous-titre), $data (Guides::render), $fontBase, $edition.
--}}
@php
    $fonts = [
        ['Unbounded', 500, 'normal', 'unbounded-latin-500-normal.woff2'],
        ['Unbounded', 600, 'normal', 'unbounded-latin-600-normal.woff2'],
        ['Unbounded', 700, 'normal', 'unbounded-latin-700-normal.woff2'],
        ['Unbounded', 600, 'normal', 'unbounded-latin-ext-600-normal.woff2'],
        ['Instrument Sans', 400, 'normal', 'instrument-sans-latin-400-normal.woff2'],
        ['Instrument Sans', 400, 'italic', 'instrument-sans-latin-400-italic.woff2'],
        ['Instrument Sans', 500, 'normal', 'instrument-sans-latin-500-normal.woff2'],
        ['Instrument Sans', 600, 'normal', 'instrument-sans-latin-600-normal.woff2'],
        ['Instrument Sans', 700, 'normal', 'instrument-sans-latin-700-normal.woff2'],
        ['Instrument Sans', 400, 'normal', 'instrument-sans-latin-ext-400-normal.woff2'],
    ];
    $brandName = $brand['name'] ?? 'Kouma';
    $site = preg_replace('#^https?://#', '', rtrim($siteUrl, '/'));
    $email = $contactEmail ?? \App\Support\Contact::email();
    $whatsapp = $contactWhatsapp ?? \App\Support\Contact::whatsapp();
    $css = fn (string $text) => json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $toc = array_values(array_filter($data['toc'], fn ($h) => $h['level'] === 2));
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $brandName }} : {{ $guide['title'] }}</title>
    <style>
        @foreach ($fonts as [$family, $weight, $style, $file])
        @font-face { font-family: '{{ $family }}'; font-weight: {{ $weight }}; font-style: {{ $style }}; src: url('{{ $fontBase }}{{ $file }}') format('woff2'); }
        @endforeach

        :root { --cobalt: #2340d9; --navy: #101b5b; --ink: #0b1340; --saffron: #ffb400; --mist: #f1f4fb; --line: #d9dff0; --text: #1f2937; --muted: #5b6477; }
        * { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin: 0; font-family: 'Instrument Sans', 'Segoe UI', Arial, sans-serif; font-size: 10pt; line-height: 1.58; color: var(--text); }

        @page { size: A4; margin: 0; }
        @page cover { margin: 0; }
        @page closing { margin: 0; }
        @page body {
            margin: 25mm 19mm 24mm 19mm;
            @top-left { content: {!! $css($brandName) !!}; font: 600 7.5pt 'Unbounded', sans-serif; color: #2340d9; padding-top: 9mm; }
            @top-right { content: {!! $css($guide['title']) !!}; font: 400 8pt 'Instrument Sans', sans-serif; color: #5b6477; padding-top: 9mm; }
            @bottom-left { content: {!! $css(\App\Support\Guides::SIGNATURE) !!}; font: italic 400 8pt 'Instrument Sans', sans-serif; color: #5b6477; padding-bottom: 8mm; }
            @bottom-right { content: counter(page); font: 600 8.5pt 'Instrument Sans', sans-serif; color: #101b5b; padding-bottom: 8mm; }
        }

        /* ---------- Couverture ---------- */
        .cover { page: cover; position: relative; width: 210mm; height: 297mm; overflow: hidden; background: var(--navy); color: #fff; break-after: page; }
        .cover .motif { position: absolute; right: -34mm; bottom: -34mm; width: 120mm; height: 120mm; opacity: .95; }
        .cover .inner { position: absolute; inset: 0; padding: 26mm 24mm; display: flex; flex-direction: column; }
        .logo { font-family: 'Unbounded', sans-serif; font-weight: 600; line-height: 1; letter-spacing: -.02em; display: inline-flex; align-items: center; }
        .logo svg { width: .92em; height: .92em; margin: 0 .04em; }
        .cover .logo { font-size: 30pt; color: #fff; }
        .cover .kicker { margin-top: auto; font-size: 10.5pt; letter-spacing: .02em; color: rgba(255,255,255,.72); }
        .cover h1 { margin: 5mm 0 0; max-width: 150mm; font-family: 'Unbounded', sans-serif; font-weight: 700; font-size: 34pt; line-height: 1.12; letter-spacing: -.02em; }
        .cover .bar { margin: 9mm 0 7mm; width: 26mm; height: 2.2mm; border-radius: 2mm; background: var(--saffron); }
        .cover .subtitle { max-width: 112mm; font-size: 13pt; line-height: 1.45; color: rgba(255,255,255,.88); }
        .cover .signature { margin-top: 38mm; font-family: 'Unbounded', sans-serif; font-weight: 600; font-size: 11pt; color: var(--saffron); }
        .cover .edition { margin-top: 2mm; font-size: 9.5pt; color: rgba(255,255,255,.65); }

        /* ---------- Sommaire ---------- */
        .toc-page { page: body; break-after: page; }
        .toc-page h2.toc-title { margin: 0 0 7mm; font-family: 'Unbounded', sans-serif; font-weight: 600; font-size: 17pt; color: var(--ink); }
        .toc-page ol { margin: 0; padding: 0; list-style: none; counter-reset: toc; }
        .toc-page li { display: flex; align-items: baseline; gap: 4mm; padding: 2.3mm 0; border-bottom: .25mm solid var(--line); font-size: 10.5pt; }
        .toc-page li::before { content: ''; flex: none; width: 1.8mm; height: 1.8mm; border-radius: 50%; background: var(--saffron); }
        .toc-page a { color: var(--ink); text-decoration: none; }
        .toc-note { margin-top: 9mm; padding: 4mm 5mm; border-radius: 2.5mm; background: var(--mist); font-size: 9.5pt; color: var(--muted); }

        /* ---------- Texte du guide ---------- */
        .guide { page: body; }
        .guide h2 { margin: 11mm 0 4mm; padding-left: 4mm; border-left: 1.6mm solid var(--saffron); font-family: 'Unbounded', sans-serif; font-weight: 600; font-size: 14.5pt; line-height: 1.25; color: var(--ink); break-after: avoid; }
        .guide h2:first-child { margin-top: 0; }
        .guide h3 { margin: 7mm 0 2.5mm; font-family: 'Unbounded', sans-serif; font-weight: 500; font-size: 11pt; line-height: 1.3; color: var(--cobalt); break-after: avoid; }
        .guide h4 { margin: 5mm 0 2mm; font-weight: 700; font-size: 10.5pt; color: var(--ink); break-after: avoid; }
        .guide p { margin: 0 0 3mm; orphans: 3; widows: 3; }
        .guide ul, .guide ol { margin: 0 0 3.5mm; padding-left: 6mm; }
        .guide li { margin: 0 0 1.2mm; }
        .guide li > p { margin: 0 0 1mm; }
        .guide a { color: var(--cobalt); text-decoration: none; border-bottom: .2mm solid rgba(35,64,217,.35); }
        .guide a.guide-anchor { display: none; }
        .guide strong { font-weight: 700; color: var(--ink); }
        .guide hr { margin: 8mm 0; border: 0; border-top: .3mm solid var(--line); }
        .guide code { padding: .2mm 1.2mm; border-radius: 1mm; background: #eaeefb; font-family: Consolas, 'Courier New', monospace; font-size: .92em; color: var(--navy); }
        .guide pre { margin: 0 0 4mm; padding: 3.5mm 4.5mm; border-radius: 2.5mm; background: var(--ink); color: #e8edff; font-size: 8pt; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
        .guide pre code { padding: 0; background: none; color: inherit; font-size: inherit; }
        .guide table { width: 100%; margin: 0 0 4.5mm; border-collapse: collapse; font-size: 9pt; break-inside: auto; }
        .guide thead { display: table-header-group; }
        .guide tr { break-inside: avoid; }
        .guide th { padding: 2mm 2.5mm; background: var(--navy); color: #fff; font-weight: 600; text-align: left; }
        .guide td { padding: 2mm 2.5mm; border-bottom: .25mm solid var(--line); vertical-align: top; }
        .guide tbody tr:nth-child(even) td { background: #f7f9fe; }
        .guide blockquote { margin: 0 0 4mm; padding: 3mm 4.5mm; border-left: 1.2mm solid var(--line); background: var(--mist); border-radius: 0 2.5mm 2.5mm 0; break-inside: avoid; }
        .guide blockquote p:last-child { margin-bottom: 0; }
        .guide .callout-title { display: block; margin-bottom: .8mm; font-family: 'Unbounded', sans-serif; font-weight: 600; font-size: 8pt; letter-spacing: .01em; }
        .guide .callout-note { border-left-color: var(--cobalt); background: #eef1fe; }
        .guide .callout-note .callout-title { color: var(--cobalt); }
        .guide .callout-tip { border-left-color: #12a06b; background: #e9f8f1; }
        .guide .callout-tip .callout-title { color: #0c7a52; }
        .guide .callout-warning { border-left-color: #d6342a; background: #fdeeed; }
        .guide .callout-warning .callout-title { color: #b02820; }
        .guide .callout-example { border-left-color: #8a5cf6; background: #f3eefe; }
        .guide .callout-example .callout-title { color: #6a3fd6; }
        .guide .callout-help { border-left-color: var(--saffron); background: #fff6dc; }
        .guide .callout-help .callout-title { color: #8a6000; }
        .guide img { max-width: 100%; }

        /* ---------- Dernière page : la signature ---------- */
        .closing { page: closing; width: 210mm; height: 297mm; break-before: page; background: var(--navy); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; position: relative; overflow: hidden; }
        .closing .motif { position: absolute; left: -40mm; top: -34mm; width: 120mm; height: 120mm; opacity: .9; }
        .closing .logo { font-size: 38pt; color: #fff; }
        .closing .bar { margin: 10mm auto 8mm; width: 22mm; height: 2mm; border-radius: 2mm; background: var(--saffron); }
        .closing .signature { font-family: 'Unbounded', sans-serif; font-weight: 600; font-size: 14pt; color: var(--saffron); }
        .closing .team { margin-top: 5mm; font-size: 11pt; color: rgba(255,255,255,.88); }
        .closing .tagline { margin-top: 1.5mm; font-size: 10pt; color: rgba(255,255,255,.65); }
        .closing .contacts { margin-top: 16mm; font-size: 10.5pt; line-height: 1.9; color: rgba(255,255,255,.9); }
        .closing .contacts strong { color: #fff; }
    </style>
</head>
<body>

    {{-- Couverture --}}
    <section class="cover">
        <svg class="motif" viewBox="0 0 48 48" aria-hidden="true"><path d="M5 21a17 17 0 0 1 34 0Z" fill="#2340d9"/><path d="M9 27a17 17 0 0 0 34 0Z" fill="#ffb400"/></svg>
        <div class="inner">
            <div class="logo">k<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M5 21a17 17 0 0 1 34 0Z" fill="#ffffff"/><path d="M9 27a17 17 0 0 0 34 0Z" fill="#ffb400"/></svg>uma</div>
            <p class="kicker">{{ $guide['subtitle'] }}</p>
            <h1>{{ $guide['title'] }}</h1>
            <div class="bar"></div>
            <p class="subtitle">{{ $guide['description'] }}</p>
            <p class="signature">{{ \App\Support\Guides::SIGNATURE }}</p>
            <p class="edition">Édition du {{ $edition }}</p>
        </div>
    </section>

    {{-- Sommaire --}}
    @if (count($toc))
        <section class="toc-page">
            <h2 class="toc-title">Dans ce guide</h2>
            <ol>
                @foreach ($toc as $entry)
                    <li><a href="#{{ $entry['id'] }}">{{ $entry['title'] }}</a></li>
                @endforeach
            </ol>
            @if ($guide['public'])
                <p class="toc-note">Une question qui n'est pas dans ce guide ? Écrivez-nous : {{ $email ?: $site }}. Nous répondons, et nous pouvons même faire les réglages à votre place.</p>
            @endif
        </section>
    @endif

    {{-- Texte --}}
    <main class="guide">
        {!! $data['html'] !!}
    </main>

    {{-- Signature --}}
    <section class="closing">
        <svg class="motif" viewBox="0 0 48 48" aria-hidden="true"><path d="M5 21a17 17 0 0 1 34 0Z" fill="#2340d9"/><path d="M9 27a17 17 0 0 0 34 0Z" fill="#ffb400"/></svg>
        <div class="logo">k<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M5 21a17 17 0 0 1 34 0Z" fill="#ffffff"/><path d="M9 27a17 17 0 0 0 34 0Z" fill="#ffb400"/></svg>uma</div>
        <div class="bar"></div>
        <p class="signature">{{ \App\Support\Guides::SIGNATURE }}</p>
        <p class="team">L'équipe {{ $brandName }}</p>
        @if (! empty($brand['tagline'])) <p class="tagline">{{ $brand['tagline'] }}</p> @endif
        <div class="contacts">
            <div><strong>Site</strong> : {{ $site }}</div>
            @if ($email) <div><strong>E-mail</strong> : {{ $email }}</div> @endif
            @if ($whatsapp) <div><strong>WhatsApp</strong> : {{ $whatsapp }}</div> @endif
            @if ($guide['public']) <div>Le chat de {{ $site }} répond aussi à vos questions.</div> @endif
        </div>
    </section>
</body>
</html>
