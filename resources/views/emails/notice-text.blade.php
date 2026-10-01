{{ $heading }}

{{ $greeting }}

@foreach ($paragraphs as $paragraph)
{{ $paragraph }}

@endforeach
@foreach (array_filter($facts, fn ($v) => $v !== null && $v !== '') as $label => $value)
{{ $label }} : {{ $value }}
@endforeach
@if ($actionLabel && $actionUrl)

{{ $actionLabel }} : {{ $actionUrl }}
@endif

--
{{ \App\Support\Guides::SIGNATURE }}
L'équipe {{ $brand['name'] ?? 'Kouma' }}
{{ rtrim($brand['url'] ?? url('/'), '/') }}@if (! empty($brand['email'])) | {{ $brand['email'] }}@endif
@if ($reason)

{{ $reason }}
@endif
