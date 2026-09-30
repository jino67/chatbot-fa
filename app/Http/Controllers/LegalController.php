<?php

namespace App\Http\Controllers;

use App\Services\PlatformSettings;

/**
 * Conditions d'utilisation et politique de confidentialite. Les informations de l'editeur viennent des
 * parametres de la plateforme ; le texte est un modele de depart qui DOIT etre relu par un juriste avant la mise en production.
 */
class LegalController extends Controller
{
    public function terms(PlatformSettings $settings)
    {
        return view('legal.terms', ['legal' => $this->legal($settings)]);
    }

    public function privacy(PlatformSettings $settings)
    {
        return view('legal.privacy', ['legal' => $this->legal($settings)]);
    }

    /** @return array<string,string> */
    private function legal(PlatformSettings $settings): array
    {
        $brand = $settings->brand();

        return [
            'company' => (string) ($settings->get('legal.company') ?: '[Raison sociale à renseigner]'),
            'address' => (string) ($settings->get('legal.address') ?: '[Adresse à renseigner]'),
            'registration' => (string) ($settings->get('legal.registration') ?: '[Numéro d\'immatriculation à renseigner]'),
            'email' => (string) ($settings->get('legal.email') ?: ($brand['email'] ?: '[E-mail de contact à renseigner]')),
            'updated' => now()->locale('fr')->translatedFormat('j F Y'),
        ];
    }
}
