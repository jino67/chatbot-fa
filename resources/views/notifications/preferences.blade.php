@php
    $hours = range(0, 23);
    $hourLabel = fn (int $h) => sprintf('%d h', $h);
@endphp
<x-app-layout title="Préférences de notifications | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Préférences de notifications" subtitle="Choisissez ce que vous recevez, sur quels appareils et à quelles heures.">
            <x-slot name="actions">
                <a href="{{ route('notifications.index') }}" class="btn-outline"><x-icon name="bell" class="h-4 w-4" /> Mes notifications</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-push-card />

        {{-- Appareils --}}
        <section class="surface">
            <div class="border-b border-slate-100 px-6 py-4">
                <h2 class="font-display text-lg font-bold text-brand-950">Vos appareils</h2>
                <p class="mt-0.5 text-sm text-slate-600">Les téléphones et ordinateurs qui reçoivent vos notifications. Retirez ceux que vous n'utilisez plus.</p>
            </div>
            @forelse ($devices as $device)
                <div class="flex items-center justify-between gap-3 px-6 py-3 text-sm {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                    <div>
                        <p class="font-medium text-brand-950">{{ $device->deviceLabel() }} @if ($device->standalone) <x-badge tone="brand">Application installée</x-badge> @endif</p>
                        <p class="text-xs text-slate-500">Activé {{ $device->created_at->locale('fr')->diffForHumans() }}@if ($device->last_success_at), dernier message reçu {{ $device->last_success_at->locale('fr')->diffForHumans() }}@endif</p>
                    </div>
                    <form method="POST" action="{{ route('push.devices.destroy', $device->id) }}" onsubmit="return confirm('Retirer cet appareil ?')">@csrf @method('DELETE')
                        <button class="text-sm font-medium text-red-600 hover:underline">Retirer</button>
                    </form>
                </div>
            @empty
                <p class="px-6 py-8 text-center text-sm text-slate-500">Aucun appareil activé pour le moment.</p>
            @endforelse
        </section>

        {{-- Ce qu'on reçoit --}}
        <form method="POST" action="{{ route('notifications.preferences.update') }}" class="surface space-y-6 p-6">
            @csrf @method('PUT')

            <div>
                <h2 class="font-display text-lg font-bold text-brand-950">Ce que vous recevez</h2>
                <p class="mt-0.5 text-sm text-slate-600">Dans tous les cas, les messages restent lisibles dans la cloche de l'application.</p>
            </div>

            <label class="flex items-start gap-3 rounded-xl bg-brand-50/60 p-4 text-sm">
                <input type="checkbox" name="push" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked($prefs['push'])>
                <span><strong class="font-semibold text-brand-950">Notifications sur mes appareils</strong><br><span class="text-slate-600">Décochée, plus rien n'apparaît sur l'écran de vos appareils ; les messages restent dans la cloche.</span></span>
            </label>

            <div class="space-y-4">
                @foreach ($categories as $key => $def)
                    @php $locked = (bool) ($def['locked'] ?? false); @endphp
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="cats[{{ $key }}]" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked($prefs['cats'][$key]) @disabled($locked)>
                        <span>
                            <strong class="font-semibold text-brand-950">{{ $def['label'] }}</strong>@if ($locked) <span class="text-xs text-slate-500">(toujours reçu)</span>@endif<br>
                            <span class="text-slate-600">{{ $def['hint'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            <div class="rounded-xl border border-slate-200 p-4">
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="quiet_on" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked($prefs['quiet']['on'])>
                    <span><strong class="font-semibold text-brand-950">Heures calmes</strong><br><span class="text-slate-600">Les nouveautés et les offres attendent le matin. Les commandes et les clients qui attendent une réponse arrivent toujours tout de suite.</span></span>
                </label>
                <div class="mt-3 flex flex-wrap items-center gap-3 pl-7 text-sm">
                    <label>De <select name="quiet_from" class="field mt-0 inline-block w-24">@foreach ($hours as $h)<option value="{{ $h }}" @selected($prefs['quiet']['from'] === $h)>{{ $hourLabel($h) }}</option>@endforeach</select></label>
                    <label>à <select name="quiet_to" class="field mt-0 inline-block w-24">@foreach ($hours as $h)<option value="{{ $h }}" @selected($prefs['quiet']['to'] === $h)>{{ $hourLabel($h) }}</option>@endforeach</select></label>
                    <span class="text-xs text-slate-500">(heure de la plateforme)</span>
                </div>
            </div>

            <div class="flex justify-end"><button class="btn-primary">Enregistrer</button></div>
        </form>
    </div>
</x-app-layout>
