{{--
    Marque : un « o » qui parle. Deux demi-cercles décalés, deux voix qui se répondent (le client, l'assistant).
    Classes d'animation : .lm-top et .lm-bottom (voir app.css). Ajouter .is-speaking sur un parent pour la faire « parler ».
--}}
@props(['tone' => 'dark'])
<svg viewBox="0 0 48 48" {{ $attributes->merge(['class' => 'logo-mark']) }} aria-hidden="true">
    <path class="lm-top" d="M5 21a17 17 0 0 1 34 0Z" fill="{{ $tone === 'light' ? '#ffffff' : '#2340D9' }}"/>
    <path class="lm-bottom" d="M9 27a17 17 0 0 0 34 0Z" fill="#FFB400"/>
</svg>
