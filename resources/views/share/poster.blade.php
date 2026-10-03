<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Affiche : {{ $company }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=unbounded:600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    <style>
        :root { --c: {{ $color }}; --ink: #0b1240; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7ef; font-family: 'Instrument Sans', system-ui, sans-serif; color: var(--ink); }
        .bar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; padding: .75rem 1rem; background: #fff; border-bottom: 1px solid #d5d9e6; }
        .bar p { margin: 0; font-size: .875rem; color: #475069; max-width: 40rem; }
        .bar button, .bar a { border: 0; border-radius: 999px; padding: .6rem 1.25rem; font: 600 .9rem inherit; cursor: pointer; text-decoration: none; }
        .bar button { background: var(--c); color: #fff; }
        .bar a { background: #eef0f7; color: var(--ink); }
        .sheet { width: 210mm; min-height: 297mm; margin: 1.5rem auto; background: #fff; display: flex; flex-direction: column; align-items: center; justify-content: space-between; text-align: center; padding: 22mm 18mm 16mm; box-shadow: 0 8px 30px rgb(11 18 64 / .15); border-top: 14mm solid var(--c); }
        .sheet h1 { font-family: 'Unbounded', system-ui, sans-serif; font-size: 34pt; line-height: 1.1; margin: 0; }
        .sheet .lead { margin: 6mm 0 0; font-size: 18pt; color: #475069; }
        .qr { width: 118mm; height: 118mm; margin: 10mm auto 0; padding: 4mm; border: 3mm solid var(--c); border-radius: 8mm; }
        .qr svg { display: block; width: 100%; height: 100%; }
        .cta { font-family: 'Unbounded', system-ui, sans-serif; font-size: 24pt; margin: 10mm 0 0; }
        .link { margin: 3mm 0 0; font-size: 14pt; color: #475069; word-break: break-all; }
        .foot { font-size: 10pt; color: #6b7390; }
        @media print {
            body { background: #fff; }
            .bar { display: none; }
            .sheet { margin: 0; box-shadow: none; width: auto; min-height: 0; height: 297mm; zoom: 1 !important; }
            @page { size: A4; margin: 0; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <p>Imprimez cette affiche (ou choisissez « Enregistrer au format PDF »). Placez-la à l'entrée, sur le comptoir ou en vitrine.</p>
        <div style="display:flex;gap:.5rem">
            <a href="{{ route('channels.show', $bot) }}">Retour</a>
            <button type="button" onclick="window.print()">Imprimer</button>
        </div>
    </div>

    <main class="sheet">
        <div>
            <h1>{{ $company }}</h1>
            <p class="lead">{{ $whatsapp ? 'Une question ? Écrivez-nous sur WhatsApp.' : 'Une question ? Notre assistant vous répond tout de suite, jour et nuit.' }}</p>
        </div>

        <div>
            <div class="qr">{!! $svg !!}</div>
            <p class="cta">Scannez pour {{ $whatsapp ? 'nous écrire' : 'discuter avec nous' }}</p>
            <p class="link">{{ $link }}</p>
        </div>

        <p class="foot">Ouvrez l'appareil photo de votre téléphone et visez le carré.@if ($branding) · Propulsé par {{ $brand['name'] }}@endif</p>
    </main>
    <script>
        // À l'écran d'un téléphone, la feuille A4 est réduite pour tenir dans la largeur (l'impression reste à taille réelle).
        (function () {
            var sheet = document.querySelector('.sheet');
            function fit() { sheet.style.zoom = Math.min(1, (window.innerWidth - 16) / (210 * 96 / 25.4)); }
            fit(); window.addEventListener('resize', fit);
        })();
    </script>
</body>
</html>
