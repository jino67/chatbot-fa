@props(['tone' => 'dark', 'size' => 'md', 'wordmark' => true])
@php
    $box = ['sm' => 'h-7 w-7', 'md' => 'h-9 w-9', 'lg' => 'h-12 w-12'][$size] ?? 'h-9 w-9';
    $text = ['sm' => 'text-lg', 'md' => 'text-xl', 'lg' => 'text-3xl'][$size] ?? 'text-xl';
    $ink = $tone === 'light' ? 'text-white' : 'text-brand-950';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    {{-- Marque : une bulle de discussion dont le « k » se lit dans le blanc, et un point safran (la voix). --}}
    <svg viewBox="0 0 40 40" class="{{ $box }} shrink-0" role="img" aria-label="{{ $brand['name'] ?? 'Kouma' }}">
        <path d="M20 3C10.6 3 3 9.9 3 18.4c0 4.6 2.2 8.7 5.7 11.5L7 36.5c-.2.8.7 1.4 1.4.9l6.4-3.8c1.7.5 3.4.7 5.2.7 9.4 0 17-6.9 17-15.4S29.4 3 20 3Z" fill="{{ $tone === 'light' ? '#ffffff' : '#2340D9' }}"/>
        <path d="M14.5 11.5v14M14.5 19.2 24.5 11.5M17.6 17.4 25.5 26" fill="none" stroke="{{ $tone === 'light' ? '#2340D9' : '#ffffff' }}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="33" cy="7" r="3.6" fill="#FFB400"/>
    </svg>
    @if ($wordmark)
        <span class="font-display {{ $text }} font-bold leading-none tracking-tight {{ $ink }}">{{ strtolower($brand['name'] ?? 'kouma') }}</span>
    @endif
</span>
