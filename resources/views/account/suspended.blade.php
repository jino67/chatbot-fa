<x-guest-layout title="Espace suspendu | {{ $brand['name'] }}">
    <h1 class="font-display text-3xl font-bold text-brand-950">Votre espace est suspendu</h1>
    <p class="mt-3 text-slate-700">
        L'espace <strong>{{ $workspace->name }}</strong> est momentanément suspendu. Vos assistants ne répondent plus, mais vos contenus sont conservés.
    </p>
    <p class="mt-3 text-slate-700">
        Contactez-nous pour le réactiver{{ $brand['email'] ? ' : '.$brand['email'] : '.' }}
    </p>
    <div class="mt-6 flex flex-wrap gap-3">
        <a href="{{ route('billing.show') }}" class="btn-primary">Voir mon abonnement</a>
        <form method="POST" action="{{ route('logout') }}">@csrf
            <button class="btn-outline">Se déconnecter</button>
        </form>
    </div>
</x-guest-layout>
