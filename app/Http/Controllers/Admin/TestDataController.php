<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TestDataReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Données de test : remettre à zéro les paiements simulés et les compteurs des espaces d'essai (super administrateur). */
class TestDataController extends Controller
{
    public const CONFIRMATION = 'REMETTRE A ZERO';

    public function index(TestDataReset $reset)
    {
        return view('admin.test-data', ['rows' => $reset->overview(), 'options' => TestDataReset::OPTIONS, 'confirmation' => self::CONFIRMATION]);
    }

    public function reset(Request $request, TestDataReset $reset): RedirectResponse
    {
        $data = $request->validate([
            'workspaces' => ['required', 'array', 'min:1'],
            'workspaces.*' => ['integer', Rule::exists('workspaces', 'id')],
            'options' => ['required', 'array', 'min:1'],
            'options.*' => [Rule::in(array_keys(TestDataReset::OPTIONS))],
            'confirmation' => ['required', 'string'],
        ], [
            'workspaces.required' => 'Cochez au moins un espace.',
            'options.required' => 'Cochez au moins une chose à remettre à zéro.',
        ]);

        if (mb_strtoupper(trim($data['confirmation'])) !== self::CONFIRMATION) {
            return back()->withInput()->withErrors(['confirmation' => 'Pour confirmer, écrivez exactement « '.self::CONFIRMATION.' ».']);
        }

        $result = $reset->reset(array_map('intval', $data['workspaces']), $data['options'], $request->user());
        $c = $result['counts'];

        return redirect()->route('admin.test-data.index')->with('status',
            "Remise à zéro faite sur {$result['workspaces']} espace(s) : {$c['payments']} paiement(s) effacé(s), {$c['plan']} offre(s) remise(s) sur Découverte, {$c['credit']} crédit(s) WhatsApp remis à 0, {$c['usage']} mesure(s) de consommation et {$c['requests']} demande(s) d'offre effacées. Une copie de ce qui a disparu est gardée dans le dossier storage/app de l'application : {$result['backup']}.");
    }
}
