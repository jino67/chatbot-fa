{{--
    Vague de transition entre deux sections. Elle prend la couleur de la section voisine et derive tres lentement,
    comme la surface de l'eau : deux couches (l'arriere plus pale et plus lente) ou une seule, plus discrete.
    Chaque couche est un seul trace continu (deux fois la meme onde) : aucune jointure visible quand elle glisse.
    Utilisation : <x-wave fill="#ffffff" /> en bas d'une section, <x-wave fill="#ffffff" position="top" /> en haut.
    La section qui la porte doit etre en position relative.
--}}
@props(['fill' => '#ffffff', 'position' => 'bottom', 'layers' => 2, 'size' => 'lg'])
@php
    $height = $size === 'lg' ? 'h-16 sm:h-24' : 'h-8 sm:h-12';
    $place = $position === 'top' ? 'top-0 rotate-180' : 'bottom-0';
    // Periode de 720 : quatre periodes = deux ondes completes ; decaler la piste de 50 % boucle sans a-coup.
    $path = 'M0 74 Q180 8 360 74 T720 74 T1080 74 T1440 74 T1800 74 T2160 74 T2520 74 T2880 74 V122 H0 Z';
@endphp
<div class="wave pointer-events-none absolute inset-x-0 {{ $place }} {{ $height }} z-[1] overflow-hidden" aria-hidden="true">
    @if ($layers > 1)
        <div class="wave-track wave-slow">
            <svg viewBox="0 0 2880 120" preserveAspectRatio="none" class="h-full w-full"><path d="{{ $path }}" fill="{{ $fill }}"/></svg>
        </div>
    @endif
    <div class="wave-track wave-fast">
        <svg viewBox="0 0 2880 120" preserveAspectRatio="none" class="h-full w-full"><path d="{{ $path }}" fill="{{ $fill }}"/></svg>
    </div>
</div>
