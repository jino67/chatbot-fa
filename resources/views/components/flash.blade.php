@if (session('status') || session('error') || session('new_password') || $errors->any())
    <div class="mx-auto max-w-7xl space-y-3 px-4 pt-6 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
                <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> <span>{{ session('status') }}</span>
            </div>
        @endif
        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ session('error') }}</div>
        @endif
        @if ($secret = session('new_password'))
            <div class="rounded-lg border border-accent-300 bg-accent-50 px-4 py-3 text-sm text-brand-950" role="status">
                <p class="font-semibold">Mot de passe provisoire de {{ $secret['email'] }}</p>
                <p class="mt-1">
                    <code class="rounded bg-white px-2 py-1 font-mono text-base tracking-wide">{{ $secret['password'] }}</code>
                </p>
                <p class="mt-2 text-xs text-slate-700">Il n'est affiché qu'une seule fois : transmettez-le à la personne, qui pourra le changer depuis son profil.</p>
            </div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                <ul class="list-disc space-y-1 ps-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
