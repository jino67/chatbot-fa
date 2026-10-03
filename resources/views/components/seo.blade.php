{{--
    Balises de référencement d'une page publique : titre, description, adresse canonique, partage (Open Graph, carte
    large), directives pour les robots et données structurées. La page d'accueil, les pages de contenu et la page
    développeurs passent toutes par ici pour rester cohérentes.
    Les données structurées sont un tableau de blocs ; chaque bloc reçoit son « @context » ici.
--}}
@props(['title', 'description', 'canonical' => null, 'type' => 'website', 'image' => null, 'noindex' => false, 'graph' => [], 'keywords' => null, 'modified' => null])
@php
    $canonical = $canonical ?: url()->current();
    $image = $image ?: url('og-image.png');
    $brandName = $brand['name'] ?? config('brand.name');
@endphp
<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
@if ($keywords)
    <meta name="keywords" content="{{ $keywords }}">
@endif
<link rel="canonical" href="{{ $canonical }}">
<meta name="robots" content="{{ $noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large, max-snippet:-1' }}">
<meta property="og:type" content="{{ $type }}">
<meta property="og:site_name" content="{{ $brandName }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:locale" content="fr_FR">
<meta property="og:image" content="{{ $image }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $brandName }} : {{ $brand['tagline'] ?? config('brand.tagline') }}">
@if ($modified)
    <meta property="article:modified_time" content="{{ $modified }}">
@endif
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $image }}">
@foreach ($graph as $block)
    <script type="application/ld+json">
    {!! json_encode(['@@context' => 'https://schema.org'] + $block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
@endforeach
