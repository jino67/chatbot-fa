@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-xl border-slate-300 text-sm shadow-sm transition focus:border-brand-500 focus:ring-brand-500']) }}>
