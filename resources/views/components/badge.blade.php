@props(['tone' => 'gray'])
@php
    $classes = match ($tone) {
        'green' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'red' => 'bg-red-50 text-red-700 ring-red-600/20',
        'amber' => 'bg-accent-50 text-accent-700 ring-accent-500/40',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'indigo', 'brand' => 'bg-brand-50 text-brand-700 ring-brand-600/20',
        default => 'bg-slate-100 text-slate-700 ring-slate-500/20',
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset $classes"]) }}>{{ $slot }}</span>
