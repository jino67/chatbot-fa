<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/** Journal d'activite : qui a fait quoi, surtout quand le personnel agit dans l'espace d'un client. */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.audit', [
            'logs' => AuditLog::with(['user', 'workspace'])
                ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
                ->when($request->query('workspace'), fn ($q, $w) => $q->where('workspace_id', $w))
                ->latest('id')->paginate(40)->withQueryString(),
        ]);
    }
}
