<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mise a jour automatique des sites et des pages Facebook connectees.
Schedule::command('platform:resync-due')->hourly()->withoutOverlapping();

// Echeances des abonnements : rappels, periode de grace, retour a l'offre gratuite.
Schedule::command('platform:subscriptions')->dailyAt('08:00')->withoutOverlapping();

// Demandes (commandes, rendez-vous, devis, personne demandée) restées sans réponse : rappels au propriétaire.
Schedule::command('leads:remind')->everyTenMinutes()->withoutOverlapping();

// Solde Twilio bas et clients proches de leur volume de messages WhatsApp : alertes par e-mail.
Schedule::command('platform:check-wallets')->hourly()->withoutOverlapping();
