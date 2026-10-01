{{--
    Illustrations de la marque : formes simples (demi-cercles, arrondis), quatre couleurs, une petite animation
    qui se joue au survol de la carte (classe .group sur le parent), ou en continu avec la classe .il-live.
    Utilisation : <x-illus name="doc" class="h-14 w-14" />
    Noms : doc, photo, globe, link, qa, shield, voice, chart, chat, inbox, question, sleep, plan (avec level 1 a 4), mic, bell, phone.
--}}
@props(['name', 'level' => 1])
<svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {{ $attributes->merge(['class' => 'il']) }}>
    @switch($name)
        @case('doc')
            <path d="M15 6h23l13 13v35a4 4 0 0 1-4 4H15a4 4 0 0 1-4-4V10a4 4 0 0 1 4-4Z" fill="#DCE4FD"/>
            <path d="M38 6l13 13h-9a4 4 0 0 1-4-4V6Z" fill="#8FA5F7"/>
            <rect x="19" y="28" width="26" height="4" rx="2" fill="#2340D9"/>
            <rect x="19" y="37" width="20" height="4" rx="2" fill="#5F7DF0"/>
            <rect x="19" y="46" width="24" height="4" rx="2" fill="#5F7DF0"/>
            <rect class="il-scan" x="15" y="24" width="34" height="9" rx="4.5" fill="#FFB400" fill-opacity=".55"/>
            @break

        @case('photo')
            <rect x="7" y="12" width="50" height="40" rx="7" fill="#DCE4FD"/>
            <path class="il-sun" d="M33 34a8.5 8.5 0 0 1 17 0Z" fill="#FFB400"/>
            <path d="M7 45l15-15 11 11 9-9 15 15v2a7 7 0 0 1-7 7H14a7 7 0 0 1-7-7Z" fill="#5F7DF0"/>
            <path class="il-spark" d="M19 15.5l1.7 4 4 1.7-4 1.7-1.7 4-1.7-4-4-1.7 4-1.7Z" fill="#E8336D"/>
            @break

        @case('globe')
            <circle cx="32" cy="32" r="21" fill="#DCE4FD"/>
            <ellipse cx="32" cy="32" rx="9" ry="21" stroke="#2340D9" stroke-width="2.6"/>
            <path d="M11 32h42M15 21h34M15 43h34" stroke="#2340D9" stroke-width="2.6" stroke-linecap="round"/>
            <g class="il-orbit"><circle cx="32" cy="6.5" r="4.5" fill="#FFB400"/></g>
            @break

        @case('link')
            <rect x="5" y="22" width="32" height="20" rx="10" stroke="#2340D9" stroke-width="5"/>
            <rect class="il-link" x="27" y="22" width="32" height="20" rx="10" stroke="#E8336D" stroke-width="5"/>
            @break

        @case('qa')
            <rect x="6" y="8" width="34" height="22" rx="9" fill="#DCE4FD"/>
            <path d="M13 30v9l9-9Z" fill="#DCE4FD"/>
            <rect x="13" y="16" width="20" height="3.5" rx="1.75" fill="#5F7DF0"/>
            <g class="il-pop">
                <rect x="24" y="30" width="34" height="24" rx="9" fill="#FFB400"/>
                <path d="M50 54v8l-9-8Z" fill="#FFB400"/>
                <rect x="31" y="38" width="20" height="3.5" rx="1.75" fill="#0B1340"/>
                <rect x="31" y="45" width="13" height="3.5" rx="1.75" fill="#0B1340" fill-opacity=".55"/>
            </g>
            @break

        @case('shield')
            <path d="M32 5 52 12.5V29c0 13.5-8.5 22.5-20 29C20.5 51.5 12 42.5 12 29V12.5Z" fill="#DCE4FD"/>
            <path class="il-draw" d="M22 31l7.5 7.5L43 24" stroke="#12A574" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" pathLength="1"/>
            @break

        @case('voice')
            <path class="il-open-top" d="M8 30a20 20 0 0 1 40 0Z" fill="#2340D9"/>
            <path class="il-open-bottom" d="M16 36a20 20 0 0 0 40 0Z" fill="#FFB400"/>
            @break

        @case('chart')
            <rect class="il-bar" x="8" y="30" width="12" height="26" rx="4" fill="#5F7DF0"/>
            <rect class="il-bar" x="26" y="18" width="12" height="38" rx="4" fill="#2340D9"/>
            <rect class="il-bar" x="44" y="26" width="12" height="30" rx="4" fill="#8FA5F7"/>
            <circle class="il-spark" cx="48" cy="12" r="7" fill="#E8336D"/>
            <path d="M45.6 10.4a2.6 2.6 0 1 1 3.7 2.3c-.9.5-1.3.9-1.3 1.8" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>
            <circle cx="48" cy="17.4" r="1" fill="#fff"/>
            @break

        @case('chat')
            <rect x="6" y="10" width="34" height="22" rx="9" fill="#DCE4FD"/>
            <path d="M12 32v8l9-8Z" fill="#DCE4FD"/>
            <g class="il-pop"><rect x="24" y="30" width="34" height="22" rx="9" fill="#2340D9"/><path d="M50 52v8l-9-8Z" fill="#2340D9"/></g>
            <circle cx="35" cy="41" r="2.2" fill="#fff"/><circle cx="41" cy="41" r="2.2" fill="#fff"/><circle cx="47" cy="41" r="2.2" fill="#fff"/>
            @break

        @case('inbox')
            <path d="M8 34l8-20a4 4 0 0 1 3.7-2.5h24.6A4 4 0 0 1 48 14l8 20v14a5 5 0 0 1-5 5H13a5 5 0 0 1-5-5Z" fill="#DCE4FD"/>
            <path d="M8 34h14a3 3 0 0 1 3 3v0a5 5 0 0 0 5 5h4a5 5 0 0 0 5-5v0a3 3 0 0 1 3-3h14" stroke="#2340D9" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            <circle class="il-spark" cx="32" cy="24" r="5" fill="#E8336D"/>
            @break

        @case('question')
            <circle cx="32" cy="32" r="22" fill="#FFF0BF"/>
            <path class="il-spark" d="M24 26a8 8 0 1 1 11.5 7.2c-2.2 1.2-3.5 2.4-3.5 5" stroke="#B87800" stroke-width="4.5" stroke-linecap="round"/>
            <circle cx="32" cy="47" r="2.8" fill="#B87800"/>
            @break

        @case('sleep')
            <path d="M10 42a22 22 0 0 1 44 0Z" fill="#2340D9"/>
            <path d="M10 44a22 22 0 0 0 44 0Z" fill="#FFB400"/>
            <path class="il-zzz" d="M44 12h9l-9 10h9" stroke="#FFB400" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            <path class="il-zzz il-zzz-2" d="M32 6h6l-6 7h6" stroke="#8FA5F7" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
            @break

        @case('plan')
            {{-- Une offre = un nombre de demi-cercles : plus l'offre est grande, plus la conversation est riche. --}}
            @if ($level >= 2)
                <path class="il-open-top" d="M9 32a19 19 0 0 1 38 0Z" fill="#2340D9"/>
                <path class="il-open-bottom" d="M17 37a19 19 0 0 0 38 0Z" fill="#FFB400"/>
            @else
                <path class="il-open-top" d="M17 40a15 15 0 0 1 30 0Z" fill="#FFB400"/>
            @endif
            @if ($level >= 3)
                <path class="il-spark" d="M42 22a8 8 0 0 1 16 0Z" fill="#12A574"/>
            @endif
            @if ($level >= 4)
                <path class="il-spark" d="M4 50a8 8 0 0 0 16 0Z" fill="#E8336D"/>
            @endif
            @break

        @case('code')
            <rect x="6" y="12" width="52" height="40" rx="9" fill="#0B1340"/>
            <path d="M22 25l-8 7 8 7M42 25l8 7-8 7" stroke="#8FA5F7" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
            <path class="il-draw" d="M35 22l-6 20" stroke="#FFB400" stroke-width="4" stroke-linecap="round" pathLength="1"/>
            @break

        @case('mic')
            <rect x="24" y="6" width="16" height="30" rx="8" fill="#2340D9"/>
            <path d="M14 30a18 18 0 0 0 36 0M32 48v10M24 58h16" stroke="#5F7DF0" stroke-width="4" stroke-linecap="round"/>
            <path class="il-wave" d="M50 18a10 10 0 0 1 0 14" stroke="#FFB400" stroke-width="3.5" stroke-linecap="round"/>
            <path class="il-wave il-wave-2" d="M56 13a18 18 0 0 1 0 24" stroke="#FFB400" stroke-width="3" stroke-linecap="round"/>
            @break
        @case('bell')
            <path d="M32 7a15 15 0 0 0-15 15v10c0 4-2 6-5 9h40c-3-3-5-5-5-9V22A15 15 0 0 0 32 7Z" fill="#DCE4FD"/>
            <path d="M25 47a7 7 0 0 0 14 0Z" fill="#2340D9"/>
            <circle class="il-pop" cx="46" cy="17" r="8" fill="#E8336D"/>
            <path d="M46 12.5v5" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
            <circle cx="46" cy="21.2" r="1.3" fill="#fff"/>
            @break

        @case('phone')
            <rect x="17" y="5" width="30" height="54" rx="8" fill="#0B1340"/>
            <rect x="21" y="12" width="22" height="38" rx="3" fill="#DCE4FD"/>
            <path class="il-open-top" d="M25 28a7 7 0 0 1 14 0Z" fill="#2340D9"/>
            <path class="il-open-bottom" d="M25 31a7 7 0 0 0 14 0Z" fill="#FFB400"/>
            <rect x="28" y="54" width="8" height="2.5" rx="1.25" fill="#5F7DF0"/>
            @break
    @endswitch
</svg>
