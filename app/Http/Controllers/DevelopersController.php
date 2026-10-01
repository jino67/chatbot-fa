<?php

namespace App\Http\Controllers;

use App\Models\Plan;

/** Page publique « Développeurs » : l'assistant en API, avec un exemple, les erreurs possibles et l'offre. */
class DevelopersController extends Controller
{
    public function __invoke()
    {
        return view('developers', [
            'plans' => Plan::forDevelopers()->where('is_public', true)->orderBy('sort')->get(),
            'endpoint' => url('/api/v1/chat'),
        ]);
    }
}
