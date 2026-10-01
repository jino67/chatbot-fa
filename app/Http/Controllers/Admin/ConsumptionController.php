<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ConsumptionReport;
use App\Services\TwilioWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Consommation de chaque client en temps reel : reponses IA, messages WhatsApp, vocal, cout estime, marge, et solde
 * du portefeuille Twilio de la plateforme. La page se rafraichit toute seule (route « live »).
 */
class ConsumptionController extends Controller
{
    public function index(ConsumptionReport $report)
    {
        return view('admin.consumption', ['report' => $report->build()]);
    }

    public function live(ConsumptionReport $report): JsonResponse
    {
        return response()->json($report->build());
    }

    /** Relit le solde Twilio sans attendre la fin du cache. */
    public function refreshWallet(TwilioWallet $wallet): RedirectResponse
    {
        $result = $wallet->balance(fresh: true);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok']
            ? sprintf('Solde Twilio : %s %s.', number_format($result['balance'], 2, ',', ' '), $result['currency'])
            : $result['error']);
    }
}
