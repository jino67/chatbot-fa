<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    /** Seule la page d'inscription s'indexe (avec sa description) ; connexion et mots de passe restent hors des résultats. */
    public function __construct(public ?string $title = null, public ?string $description = null, public bool $indexable = false) {}

    public function render(): View
    {
        return view('layouts.guest');
    }
}
