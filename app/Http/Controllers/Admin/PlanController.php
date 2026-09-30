<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Offres d'abonnement : prix, quotas et options, modifiables sans toucher au code. */
class PlanController extends Controller
{
    public function index()
    {
        return view('admin.plans.index', [
            'plans' => Plan::orderBy('sort')->get(),
            'counts' => Workspace::selectRaw('plan, count(*) as total')->groupBy('plan')->pluck('total', 'plan'),
        ]);
    }

    public function create()
    {
        return view('admin.plans.form', ['plan' => new Plan(['currency' => 'XOF', 'period_months' => 1, 'limits' => Plan::FALLBACK_LIMITS, 'features' => [], 'is_public' => true, 'sort' => 10])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $plan = Plan::create($data);
        $this->onlyOneDefault($plan);

        AuditLog::record('plan.created', $plan->slug);

        return redirect()->route('admin.plans.index')->with('status', "Offre « {$plan->name} » créée.");
    }

    public function edit(Plan $plan)
    {
        return view('admin.plans.form', ['plan' => $plan]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->validated($request, $plan));
        $this->onlyOneDefault($plan);

        AuditLog::record('plan.updated', $plan->slug, ['price' => $plan->price]);

        return redirect()->route('admin.plans.index')->with('status', "Offre « {$plan->name} » enregistrée. Les changements s'appliquent immédiatement aux espaces concernés.");
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->is_default) {
            return back()->with('error', "L'offre par défaut ne peut pas être supprimée : désignez d'abord une autre offre par défaut.");
        }
        if (Workspace::where('plan', $plan->slug)->exists()) {
            return back()->with('error', 'Des espaces utilisent encore cette offre : changez leur offre avant de la supprimer, ou retirez-la simplement de la page des tarifs.');
        }

        $plan->delete();
        AuditLog::record('plan.deleted', $plan->slug);

        return back()->with('status', 'Offre supprimée.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?Plan $plan): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['required', Rule::in(['XOF', 'KMF', 'EUR', 'USD'])],
            'period_months' => ['required', 'integer', 'min:1', 'max:12'],
            'sort' => ['required', 'integer', 'min:0', 'max:999'],
            'limits' => ['required', 'array'],
            'features' => ['nullable', 'array'],
        ];
        foreach (Plan::limitFields() as $field) {
            $rules['limits.'.$field['key']] = ['required', 'integer', 'min:0', 'max:10000000'];
        }
        if (! $plan) {
            $rules['slug'] = ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/', 'unique:plans,slug'];
        }

        $data = $request->validate($rules);

        return array_filter([
            'slug' => $data['slug'] ?? null,
            'name' => $data['name'],
            'tagline' => $data['tagline'] ?? null,
            'price' => $data['price'],
            'currency' => $data['currency'],
            'period_months' => $data['period_months'],
            'sort' => $data['sort'],
            'limits' => array_map('intval', $data['limits']),
            'features' => collect(Plan::featureFields())->mapWithKeys(fn ($f) => [$f['key'] => $request->boolean('features.'.$f['key'])])->all(),
            'is_public' => $request->boolean('is_public'),
            'is_default' => $request->boolean('is_default'),
            'is_highlighted' => $request->boolean('is_highlighted'),
        ], fn ($v) => $v !== null);
    }

    private function onlyOneDefault(Plan $plan): void
    {
        if ($plan->is_default) {
            Plan::where('id', '!=', $plan->id)->update(['is_default' => false]);
        } elseif (! Plan::where('is_default', true)->exists()) {
            $plan->update(['is_default' => true]);
        }
    }
}
