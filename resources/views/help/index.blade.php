@php
    $name = $brand['name'];
    $title = 'Aide et guides : prendre Kouma en main';
    $description = "Les guides complets pour utiliser Kouma sur votre site et sur WhatsApp, et pour intégrer l'assistant à votre application. À lire en ligne ou en PDF.";
    $graph = [[
        '@type' => 'CollectionPage',
        'name' => $title,
        'description' => $description,
        'url' => route('help.index'),
        'inLanguage' => 'fr',
        'isPartOf' => ['@type' => 'WebSite', 'name' => $name, 'url' => url('/')],
        'hasPart' => $guides->map(fn ($g) => ['@type' => 'TechArticle', 'name' => $g['title'], 'url' => $g['url']])->all(),
    ]];
    $faq = [
        ['Combien de temps faut-il pour créer son assistant ?', "Environ une demi-heure si vos documents sont prêts : vous répondez à quelques questions, vous ajoutez vos documents, vous testez, et c'est en ligne. Et si vous préférez, notre équipe s'en charge pour vous."],
        ['Faut-il savoir programmer ?', "Non. Pour votre site, il suffit de copier une ligne de code et de la coller dans la page, ce que votre webmaster fait en une minute. Pour WhatsApp, notre équipe s'occupe de tout."],
        ["L'assistant peut-il inventer des réponses ?", "Il répond d'après vos documents, et il le dit quand il ne sait pas. Dans ce cas, il propose de joindre votre équipe et vous prévient. Vous gardez la main : vous pouvez prendre n'importe quelle conversation à tout moment."],
        ['Qui s\'occupe de WhatsApp ?', "Notre équipe technique : vous indiquez votre numéro, nous réalisons l'activation (compte WhatsApp Business, vérification, configuration) et nous vous prévenons quand c'est prêt."],
        ['Où trouver de l\'aide si je suis bloqué ?', "Dans le guide d'utilisation (avec un dépannage), dans le chat web de cette page, ou en nous écrivant par e-mail ou sur WhatsApp. Nous répondons vite."],
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo :title="$title" :description="$description" :canonical="route('help.index')" :graph="$graph" />
    <x-pwa-meta />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=unbounded:500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white">

<x-site-nav :name="$name" :landing="false" />

<main>
    <section class="wax relative overflow-hidden text-white" data-ripple-field>
        <div class="relative mx-auto max-w-4xl px-4 pb-24 pt-28 sm:px-6 lg:pb-32 lg:pt-32">
            <nav aria-label="Fil d'Ariane" class="text-sm text-white/75">
                <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <li><a href="{{ route('home') }}" class="hover:text-white">Accueil</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-white" aria-current="page">Aide et guides</li>
                </ol>
            </nav>
            <h1 class="mt-6 max-w-3xl font-display text-[1.75rem] font-bold leading-[1.15] sm:text-[2.25rem] lg:text-[2.5rem]">Prenez {{ $name }} en main, à votre rythme.</h1>
            <p class="mt-5 max-w-2xl text-lg leading-relaxed text-white/90">Deux guides complets, écrits pas à pas : l'un pour les entreprises qui utilisent {{ $name }}, l'autre pour les développeurs qui veulent l'intégrer. À lire ici, ou à télécharger en PDF.</p>
        </div>
        <x-wave fill="#ffffff" />
    </section>

    <section class="mx-auto max-w-5xl px-4 pb-6 pt-14 sm:px-6 lg:pt-20" aria-label="Les guides">
        <div class="grid gap-6 md:grid-cols-2">
            @foreach ($guides as $guide)
                <article class="group flex flex-col rounded-3xl rounded-bl-lg border border-slate-200 bg-white p-7 shadow-lift transition hover:-translate-y-1 hover:border-brand-300">
                    <span class="grid h-12 w-12 place-items-center rounded-2xl bg-brand-50 text-brand-700"><x-icon :name="$guide['key'] === 'client' ? 'book' : 'chip'" class="h-6 w-6" /></span>
                    <h2 class="mt-5 font-display text-xl font-bold text-brand-950"><a href="{{ $guide['url'] }}" class="after:absolute after:inset-0 hover:text-brand-700">{{ $guide['title'] }}</a></h2>
                    <p class="mt-1 text-sm font-medium text-brand-700">{{ $guide['subtitle'] }}</p>
                    <p class="mt-3 flex-1 text-slate-600">{{ $guide['description'] }}</p>
                    <div class="relative z-10 mt-6 flex flex-wrap items-center gap-3">
                        <a href="{{ $guide['url'] }}" class="btn-primary">Lire le guide</a>
                        @if ($guide['pdf_url'])
                            <a href="{{ $guide['pdf_url'] }}" download class="btn-outline">Télécharger le PDF</a>
                        @endif
                    </div>
                    <p class="mt-4 text-xs text-slate-500">Mis à jour le {{ \Carbon\Carbon::parse($guide['updated'])->translatedFormat('j F Y') }}</p>
                </article>
            @endforeach
        </div>

        <div class="mt-14">
            <h2 class="font-display text-xl font-bold text-brand-950 sm:text-2xl">Pour commencer en quelques minutes</h2>
            <ol class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['Créez votre compte', 'Gratuit pour essayer, sans carte bancaire.', route('register')],
                    ['Créez votre assistant', 'Quelques réponses, et la consigne est rédigée pour vous.', route('help.client').'#2-creer-votre-assistant'],
                    ['Donnez-lui vos documents', 'Tarifs, horaires, catalogue, site web : il apprend tout.', route('help.client').'#3-donner-ses-connaissances'],
                    ['Mettez-le en ligne', 'Sur votre site en une ligne, sur WhatsApp avec notre équipe.', route('help.client').'#6-mettre-en-ligne'],
                ] as [$step, $text, $href])
                    <li>
                        <a href="{{ $href }}" class="flex h-full flex-col rounded-2xl rounded-bl-md bg-brand-50 p-5 transition hover:-translate-y-0.5 hover:bg-brand-100">
                            <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white">{{ $loop->iteration }}</span>
                            <span class="mt-3 font-semibold text-brand-950">{{ $step }}</span>
                            <span class="mt-1 text-sm text-slate-600">{{ $text }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </div>

        <div class="mt-16">
            <h2 class="font-display text-xl font-bold text-brand-950 sm:text-2xl">Questions fréquentes</h2>
            <div class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                @foreach ($faq as [$q, $a])
                    <details class="group py-5">
                        <summary class="flex cursor-pointer items-center justify-between gap-4 font-semibold text-brand-950 transition hover:text-brand-700">
                            {{ $q }}
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600 transition duration-300 group-open:rotate-45 group-open:bg-brand-600 group-open:text-white" aria-hidden="true"><x-icon name="x" class="h-4 w-4 rotate-45" /></span>
                        </summary>
                        <p class="pt-3 leading-relaxed text-slate-600">{{ $a }}</p>
                    </details>
                @endforeach
            </div>
        </div>

        <div class="mt-16">
            @include('help._contact')
        </div>

        @include('help._signature', ['class' => 'mt-16 pb-10'])
    </section>
</main>

<x-site-footer :name="$name" :aliases="[]" :wa="null" :landing="false" />

@if ($landingBotKey)
    <script src="{{ url('/widget/widget.js') }}" data-bot="{{ $landingBotKey }}" async></script>
@endif
</body>
</html>
