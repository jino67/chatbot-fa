<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Workspace;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Recharge de messages WhatsApp : le client paie par Mobile Money (pas besoin de carte bancaire), l'equipe enregistre
 * le paiement et ajoute les messages au credit de l'espace. Les messages sont ensuite consommes apres le volume de l'offre.
 */
class WalletController extends Controller
{
    public function topup(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'integer', 'min:1', 'max:1000000'],
            'amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($request, $workspace, $data) {
            Payment::create([
                'workspace_id' => $workspace->id,
                'plan' => 'recharge_whatsapp',
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? $workspace->currency,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'period_months' => 0,
                'paid_at' => now(),
                'notes' => number_format($data['messages'], 0, ',', ' ').' messages WhatsApp',
                'recorded_by' => $request->user()->id,
            ]);

            $workspace->increment('wa_credit', $data['messages']);
        });

        AuditLog::record('wallet.topup', $workspace->name, ['messages' => $data['messages'], 'amount' => $data['amount']], $workspace->id);

        return back()->with('status', number_format($data['messages'], 0, ',', ' ').' messages WhatsApp ajoutés au crédit de « '.$workspace->name.' ».');
    }
}
