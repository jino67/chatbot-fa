@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-brand-900']) }}>
    {{ $value ?? $slot }}
</label>
