<x-mail.layout :preheader="$preheader ?: ($paragraphs[0] ?? null)" :reason="$reason" :tone="$tone" :settings="$settings">
    <x-mail.title :tone="$tone">{{ $heading }}</x-mail.title>

    <p>{{ $greeting }}</p>
    @foreach ($paragraphs as $paragraph)
        <p>{{ $paragraph }}</p>
    @endforeach

    <x-mail.facts :items="$facts" />

    @if ($actionLabel && $actionUrl)
        <div style="margin:10px 0 6px;">
            <x-mail.button :url="$actionUrl">{{ $actionLabel }}</x-mail.button>
        </div>
        <p style="margin:14px 0 0;font-size:12.5px;color:#9CA3AF;">Le bouton ne s'ouvre pas ? Copiez cette adresse dans votre navigateur : <span style="word-break:break-all;">{{ $actionUrl }}</span></p>
    @endif
</x-mail.layout>
