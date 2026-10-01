<x-guest-layout title="Vérifiez votre e-mail | {{ $brand['name'] }}">
    <h1 class="font-display text-3xl font-bold text-brand-950">Vérifiez votre adresse e-mail</h1>
    <p class="mt-2 text-slate-600">Nous venons de vous envoyer un lien de confirmation. Ouvrez-le pour activer votre compte. Rien reçu ? Regardez dans les courriers indésirables, ou demandez un nouveau lien.</p>

    @if (session('status') == 'verification-link-sent')
        <div class="mt-5 flex items-start gap-3 rounded-xl rounded-bl-sm border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> <span>Un nouveau lien vient d'être envoyé à votre adresse e-mail.</span>
        </div>
    @endif

    <div class="mt-8 flex flex-wrap items-center gap-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button class="btn-primary px-6 py-3">Renvoyer le lien</button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm font-medium text-slate-600 underline decoration-slate-300 underline-offset-2 hover:text-brand-800">Se déconnecter</button>
        </form>
    </div>
</x-guest-layout>
