@props(['title', 'subtitle' => null])
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        <h1 class="font-display text-2xl font-bold leading-tight text-brand-950 sm:text-[1.75rem]">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 max-w-2xl text-sm text-slate-600">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
