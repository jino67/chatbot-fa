{{--
    Boutons « Continuer avec Google, Apple, Microsoft, Facebook » : seulement ceux que l'administration a réglés (Paramètres).
    Sans fournisseur réglé, rien ne s'affiche, et la page reste celle d'avant.
--}}
@props(['divider' => 'ou avec votre e-mail'])
@php
    $providers = app(\App\Social\Auth\SocialLogin::class)->enabled();
    $icons = [
        'google' => '<svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47a5.54 5.54 0 0 1-2.4 3.63v3h3.88c2.27-2.09 3.54-5.17 3.54-8.87z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3c-1.08.72-2.45 1.16-4.05 1.16-3.13 0-5.78-2.11-6.73-4.96H1.27v3.09A11.99 11.99 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.27 14.29A7.2 7.2 0 0 1 4.9 12c0-.8.14-1.57.37-2.29V6.62H1.27A11.99 11.99 0 0 0 0 12c0 1.94.46 3.77 1.27 5.38l4-3.09z"/><path fill="#EA4335" d="M12 4.75c1.76 0 3.34.61 4.59 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.69 1.27 6.62l4 3.09C6.22 6.86 8.87 4.75 12 4.75z"/></svg>',
        'apple' => '<svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor" aria-hidden="true"><path d="M12.15 6.9c-.95 0-2.42-1.08-3.96-1.04-2.04.03-3.91 1.18-4.96 3.01-2.12 3.68-.55 9.1 1.52 12.09 1.01 1.45 2.21 3.09 3.79 3.04 1.52-.06 2.09-.99 3.94-.99 1.83 0 2.35.99 3.96.95 1.64-.03 2.68-1.48 3.68-2.95 1.16-1.69 1.64-3.33 1.66-3.42-.04-.01-3.18-1.22-3.22-4.86-.03-3.04 2.48-4.49 2.6-4.56-1.43-2.09-3.62-2.32-4.39-2.38-2-.16-3.68 1.09-4.62 1.09zM15.53 3.83c.84-1.01 1.4-2.43 1.25-3.83-1.21.05-2.66.8-3.53 1.82-.78.9-1.45 2.34-1.27 3.71 1.34.1 2.72-.69 3.55-1.7z"/></svg>',
        'microsoft' => '<svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true"><path fill="#F25022" d="M1 1h10v10H1z"/><path fill="#7FBA00" d="M13 1h10v10H13z"/><path fill="#00A4EF" d="M1 13h10v10H1z"/><path fill="#FFB900" d="M13 13h10v10H13z"/></svg>',
        'facebook' => '<svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor" aria-hidden="true"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07"/></svg>',
    ];
    $styles = [
        'google' => 'bg-white text-slate-800 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
        'apple' => 'bg-black text-white hover:bg-slate-900',
        'microsoft' => 'bg-white text-slate-800 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
        'facebook' => 'bg-[#1877F2] text-white hover:bg-[#166fe5]',
    ];
@endphp
@if ($providers)
    <div {{ $attributes->merge(['class' => 'mt-8']) }}>
        <div class="space-y-2.5">
            @foreach ($providers as $key => $provider)
                <a href="{{ route('social.redirect', $key) }}" data-social="{{ $key }}"
                   class="flex h-11 w-full items-center justify-center gap-3 rounded-xl px-4 text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200 {{ $styles[$key] }}">
                    {!! $icons[$key] !!} Continuer avec {{ $provider->label() }}
                </a>
            @endforeach
        </div>
        <div class="mt-6 flex items-center gap-3 text-xs text-slate-500" role="separator">
            <span class="h-px flex-1 bg-slate-200"></span><span>{{ $divider }}</span><span class="h-px flex-1 bg-slate-200"></span>
        </div>
    </div>
@endif
