{{--
    « Mise en route » : où en est l'assistant (décrit, nourri, testé, en ligne, alertes), calculé sur ce qui existe vraiment
    (voir App\Support\Onboarding). Le bouton mène à la prochaine étape ; la carte disparaît quand tout est fait.
--}}
@props(['bot', 'onboarding'])
@if ($onboarding['done'] < $onboarding['total'])
    <section class="surface overflow-hidden" aria-labelledby="onboarding-{{ $bot->id }}">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 px-6 py-4">
            <div>
                <h2 id="onboarding-{{ $bot->id }}" class="font-display text-lg font-bold">Mise en route de « {{ $bot->name }} »</h2>
                <p class="mt-0.5 text-sm text-slate-600">{{ $onboarding['done'] }} étape{{ $onboarding['done'] > 1 ? 's' : '' }} sur {{ $onboarding['total'] }}. Votre progression est enregistrée : reprenez quand vous voulez.</p>
            </div>
            @if ($onboarding['next'])
                <a href="{{ $onboarding['next']['url'] }}" class="btn-primary">{{ $onboarding['next']['label'] }}</a>
            @endif
        </div>
        <div class="h-1.5 bg-slate-100" role="progressbar" aria-valuenow="{{ $onboarding['percent'] }}" aria-valuemin="0" aria-valuemax="100">
            <div class="bar-fill h-full bg-feuille-500" style="width: {{ $onboarding['percent'] }}%"></div>
        </div>
        <ol class="divide-y divide-slate-100">
            @foreach ($onboarding['steps'] as $step)
                <li>
                    <a href="{{ $step['url'] }}" class="flex items-start gap-3 px-6 py-3 transition hover:bg-slate-50">
                        <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full {{ $step['done'] ? 'bg-feuille-500 text-white' : 'border-2 border-slate-300 text-transparent' }}"><x-icon name="check" class="h-3.5 w-3.5" /></span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold {{ $step['done'] ? 'text-slate-500 line-through' : 'text-brand-950' }}">{{ $step['label'] }}</span>
                            @unless ($step['done']) <span class="block text-xs text-slate-500">{{ $step['hint'] }}</span> @endunless
                        </span>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>
@endif
