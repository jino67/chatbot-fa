<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La devise du compte : un choix explicite, enregistré sur l'espace du client (jamais dans le navigateur), qui règle
 * l'affichage des prix, de la facturation et des paiements enregistrés ensuite. Les paiements passés gardent leur devise.
 */
class CurrencyController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['currency' => ['required', Rule::in(Currency::codes())]]);

        $workspace = $request->user()->currentWorkspace();
        if (! $workspace) {
            return back()->with('error', "Entrez d'abord dans l'espace d'un client pour changer sa devise.");
        }

        if ($workspace->currency !== $data['currency']) {
            Currency::remember($request, $data['currency']);
            AuditLog::record('workspace.currency', $workspace->name, ['currency' => $data['currency']], $workspace->id);
        }

        return back()->with('status', 'Devise du compte : '.Currency::ALL[$data['currency']]['name'].'. Les prix et la facturation s\'affichent désormais ainsi.');
    }
}
