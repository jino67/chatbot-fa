@props(['title', 'hint' => null])
<section {{ $attributes->merge(['class' => 'surface']) }}>
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-6 py-4">
        <div class="min-w-0">
            <h2 class="font-display text-lg font-bold text-brand-950">{{ $title }}</h2>
            @if ($hint) <p class="mt-0.5 max-w-2xl text-sm text-slate-600">{{ $hint }}</p> @endif
        </div>
        @isset($actions) <div class="flex items-center gap-2">{{ $actions }}</div> @endisset
    </div>
    {{ $slot }}
</section>
