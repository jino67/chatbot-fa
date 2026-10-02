<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AuditCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Journal d'activite : qui a fait quoi, surtout quand le personnel agit dans l'espace d'un client. */
class AuditController extends Controller
{
    public const PERIODS = [1 => '24 heures', 7 => '7 jours', 30 => '30 jours', 0 => 'Tout'];

    public function index(Request $request)
    {
        $logs = $this->filtered($request);

        // Les repères de la période choisie (hors filtre « sensibles », pour comparer).
        $period = $this->filtered($request, withLevel: false);
        $sensitive = AuditCatalog::sensitiveActions();

        return view('admin.audit', [
            'logs' => (clone $logs)->with(['user:id,name,email', 'workspace:id,name'])->latest('id')->paginate(40)->withQueryString(),
            'categories' => AuditCatalog::CATEGORIES,
            'periods' => self::PERIODS,
            'people' => User::whereIn('id', AuditLog::query()->whereNotNull('user_id')->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'workspaces' => Workspace::whereIn('id', AuditLog::query()->whereNotNull('workspace_id')->select('workspace_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'total' => (clone $period)->count(),
            'peopleCount' => (clone $period)->whereNotNull('user_id')->distinct()->count('user_id'),
            'sensitiveCount' => (clone $period)->whereIn('action', $sensitive)->count(),
            'filtered' => $request->hasAny(['categorie', 'personne', 'client', 'q', 'niveau']),
        ]);
    }

    /** Le journal filtré en CSV (séparateur « ; » et BOM pour Excel), 5 000 lignes au plus, formules neutralisées. */
    public function export(Request $request): StreamedResponse
    {
        $logs = $this->filtered($request)->with(['user:id,name,email', 'workspace:id,name'])->latest('id')->limit(5000)->get();

        AuditLog::record('audit.exported', 'Journal d\'activité', ['lignes' => $logs->count()]);

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Personne', 'E-mail', 'Action', 'Code', 'Niveau', 'Cible', 'Espace', 'Détail', 'Adresse IP'], ';');
            foreach ($logs as $log) {
                $row = [
                    $log->created_at->format('Y-m-d H:i:s'), $log->user?->name ?? 'Système', $log->user?->email, AuditCatalog::label($log->action), $log->action,
                    AuditCatalog::level($log->action), $log->subject, $log->workspace?->name, implode(' ; ', AuditCatalog::details($log->meta)), $log->ip,
                ];
                // Une cellule qui commence par = + - @ serait lue comme une formule par le tableur : on la neutralise.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row), ';');
            }
            fclose($out);
        }, 'kouma-journal-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Builder<AuditLog> */
    private function filtered(Request $request, bool $withLevel = true): Builder
    {
        $days = array_key_exists((int) $request->query('jours', 30), self::PERIODS) ? (int) $request->query('jours', 30) : 30;
        $prefixes = AuditCatalog::prefixes((string) $request->query('categorie'));
        $term = trim(mb_substr((string) $request->query('q'), 0, 80));

        return AuditLog::query()
            ->when($days > 0, fn (Builder $q) => $q->where('created_at', '>=', now()->subDays($days)))
            ->when($prefixes !== [], fn (Builder $q) => $q->where(function (Builder $w) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $w->orWhere('action', 'like', $prefix.'%');
                }
            }))
            ->when($request->integer('personne'), fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($request->integer('client'), fn (Builder $q, $id) => $q->where('workspace_id', $id))
            ->when($withLevel && $request->query('niveau') === 'sensible', fn (Builder $q) => $q->whereIn('action', AuditCatalog::sensitiveActions()))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('subject', 'like', $like)->orWhere('action', 'like', $like));
            });
    }
}
