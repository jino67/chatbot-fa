<x-app-layout title="{{ $meta['title'] }} | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header :title="$meta['title']" :subtitle="$meta['subtitle'].' · mis à jour le '.\Carbon\Carbon::parse($guide['updated'])->translatedFormat('j F Y')">
            <x-slot name="actions">
                @if ($hasPdf)
                    <a href="{{ route('staff.guide.pdf', $key) }}" target="_blank" rel="noopener" class="btn-outline">Ouvrir le PDF</a>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-10 lg:px-8"
         x-data="{ active: '' }"
         x-init="if ('IntersectionObserver' in window) { const io = new IntersectionObserver((entries) => entries.forEach((e) => { if (e.isIntersecting) active = e.target.id; }), { rootMargin: '-15% 0px -70% 0px' }); $root.querySelectorAll('.guide h2[id], .guide h3[id]').forEach((h) => io.observe(h)); }">

        <aside class="mb-8 lg:mb-0">
            <details class="rounded-2xl bg-brand-50 p-4 lg:hidden">
                <summary class="cursor-pointer font-semibold text-brand-950">Sommaire du guide</summary>
                <nav class="guide-toc mt-3" aria-label="Sommaire">
                    @foreach ($guide['toc'] as $item)
                        <a href="#{{ $item['id'] }}" data-level="{{ $item['level'] }}">{{ $item['title'] }}</a>
                    @endforeach
                </nav>
            </details>
            <nav class="guide-toc sticky top-6 hidden max-h-[calc(100vh-4rem)] overflow-y-auto lg:block" aria-label="Sommaire">
                <p class="mb-2 px-3 font-display text-xs font-bold uppercase tracking-wide text-slate-500">Sommaire</p>
                @foreach ($guide['toc'] as $item)
                    <a href="#{{ $item['id'] }}" data-level="{{ $item['level'] }}" :data-on="active === '{{ $item['id'] }}'">{{ $item['title'] }}</a>
                @endforeach
            </nav>
        </aside>

        <article class="min-w-0">
            <div class="mb-6 rounded-xl border border-accent-300 bg-accent-50 px-4 py-3 text-sm text-slate-700">
                Document interne : il décrit des procédures d'exploitation. Ne le partagez pas en dehors de l'équipe.
            </div>
            <div class="guide surface max-w-3xl p-6 sm:p-10">{!! $guide['html'] !!}</div>
            @include('help._signature', ['class' => 'mt-10 pb-6'])
        </article>
    </div>
</x-app-layout>
