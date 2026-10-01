<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChatImportController;
use App\Http\Controllers\BotDraftController;
use App\Http\Controllers\AnalyticsCollectController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\StaffGuideController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DevelopersController;
use App\Http\Controllers\FacebookController;
use App\Http\Controllers\InstructionController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PlaygroundController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SeoPageController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Http\Controllers\Webhooks\TwilioWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pages publiques
|--------------------------------------------------------------------------
*/
Route::get('/', LandingController::class)->name('home');
Route::get('/developpeurs', DevelopersController::class)->name('developers');
Route::get('/conditions', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/confidentialite', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/robots.txt', [LandingController::class, 'robots']);
Route::get('/llms.txt', [LandingController::class, 'llms']);

// Mesure d'audience : lots d'événements du navigateur (voir resources/js/analytics.js et App\Services\Analytics).
Route::post('/a/e', [AnalyticsCollectController::class, 'store'])->middleware('throttle:analytics')->name('analytics.collect');

// Aide : les guides d'utilisation et du développeur, avec leur PDF.
Route::get('/aide', [HelpController::class, 'index'])->name('help.index');
Route::get('/aide/guide-client', [HelpController::class, 'show'])->defaults('guide', 'client')->name('help.client');
Route::get('/aide/guide-developpeur', [HelpController::class, 'show'])->defaults('guide', 'developpeur')->name('help.developer');

// Pages de contenu pour le référencement : une route par page de config/seo.php, plus la page « Ressources ».
Route::get('/ressources', [SeoPageController::class, 'hub'])->name('seo.hub');
foreach ((array) config('seo.pages', []) as $key => $page) {
    Route::get($page['path'], [SeoPageController::class, 'show'])->defaults('key', $key)->name('seo.'.$key);
}
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

// Memorise la devise choisie sur les pages de tarifs (appele par le selecteur, sans rechargement de la page).
Route::get('/devise/{code}', function (Illuminate\Http\Request $request, string $code) {
    $code = strtoupper($code);
    abort_unless(App\Support\Currency::isValid($code), 404);
    App\Support\Currency::remember($request, $code);

    return response()->noContent();
})->where('code', '[A-Za-z]{3}')->name('currency.set');
Route::get('/sitemap.xml', [LandingController::class, 'sitemap']);

// Reponse vocale que Twilio vient chercher : adresse signee (chemin relatif), valable deux heures.
Route::get('/media/voice/{name}', [MediaController::class, 'voice'])->middleware('signed:relative')->where('name', '[A-Za-z0-9]{40}\.(ogg|mp3|wav)')->name('media.voice');

// Page de demonstration partageable : le widget d'un assistant sur une page vierge.
Route::get('/demo/{publicKey}', [DemoController::class, 'show'])->name('demo');

// Webhooks WhatsApp (authentifies par signature, hors CSRF : voir bootstrap/app.php).
Route::prefix('webhooks/whatsapp')->group(function () {
    Route::get('meta', [MetaWebhookController::class, 'verify']);
    Route::post('meta', [MetaWebhookController::class, 'receive']);
    Route::post('twilio/{channel}', [TwilioWebhookController::class, 'receive']);
});

/*
|--------------------------------------------------------------------------
| Espace client (le personnel y entre pour gerer un client)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'workspace'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Cles d'API de l'offre developpeurs.
    Route::get('developers/keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('developers/keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::delete('developers/keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

    // Demandes à traiter et alertes du propriétaire
    Route::get('demandes', [LeadController::class, 'index'])->name('leads.index');
    Route::patch('demandes/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::get('alertes', [AlertController::class, 'edit'])->name('alerts.edit');
    Route::put('alertes', [AlertController::class, 'update'])->name('alerts.update');
    Route::match(['post', 'put'], 'alertes/modele', [AlertController::class, 'template'])->name('alerts.template');
    Route::post('import/option', [ChatImportController::class, 'requestOption'])->name('import.option');

    Route::match(['post', 'put'], 'brouillon/assistant', [BotDraftController::class, 'save'])->name('bots.draft.save');
    Route::delete('brouillon/assistant', [BotDraftController::class, 'discard'])->name('bots.draft.discard');
    Route::resource('bots', BotController::class)->except(['show']);

    Route::prefix('bots/{bot}')->scopeBindings()->group(function () {
        Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
        Route::post('sources', [SourceController::class, 'store'])->name('sources.store');
        Route::get('modele-catalogue', [SourceController::class, 'template'])->name('sources.template');
        Route::put('sources/{source}/content', [SourceController::class, 'content'])->name('sources.content');
        Route::delete('sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');
        Route::post('sources/{source}/resync', [SourceController::class, 'resync'])->name('sources.resync');

        Route::get('facebook/connect', [FacebookController::class, 'connect'])->name('facebook.connect');
        Route::post('facebook/select', [FacebookController::class, 'select'])->name('facebook.select');

        Route::get('instructions', [InstructionController::class, 'edit'])->name('instructions.edit');
        Route::put('instructions', [InstructionController::class, 'update'])->name('instructions.update');
        Route::put('instructions/profile', [InstructionController::class, 'profile'])->name('instructions.profile');
        Route::post('instructions/reset', [InstructionController::class, 'reset'])->name('instructions.reset');
        Route::post('instructions/polish', [InstructionController::class, 'polish'])->name('instructions.polish');

        Route::get('playground', [PlaygroundController::class, 'show'])->name('playground.show');
        Route::post('playground', [PlaygroundController::class, 'ask'])->name('playground.ask');
        Route::post('playground/reset', [PlaygroundController::class, 'reset'])->name('playground.reset');

        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::post('conversations/{conversation}/reply', [ConversationController::class, 'reply'])->name('conversations.reply');
        Route::post('conversations/{conversation}/template', [ConversationController::class, 'template'])->name('conversations.template');
        Route::post('conversations/{conversation}/status', [ConversationController::class, 'status'])->name('conversations.status');

        Route::get('analytics', [AnalyticsController::class, 'show'])->name('analytics.show');
        Route::post('analytics/answer', [AnalyticsController::class, 'answer'])->name('analytics.answer');

        Route::get('channels', [ChannelController::class, 'show'])->name('channels.show');
        Route::post('channels/whatsapp-request', [ChannelController::class, 'requestWhatsApp'])->name('channels.whatsapp-request');

        Route::get('import', [ChatImportController::class, 'show'])->name('import.show');
        Route::post('import', [ChatImportController::class, 'upload'])->name('import.upload');
        Route::post('import/analyse', [ChatImportController::class, 'analyze'])->name('import.analyze');
        Route::post('import/valider', [ChatImportController::class, 'commit'])->name('import.commit');
        Route::delete('import', [ChatImportController::class, 'cancel'])->name('import.cancel');
        Route::put('import/style', [ChatImportController::class, 'style'])->name('import.style');

        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::post('templates/sync', [TemplateController::class, 'sync'])->name('templates.sync');
        Route::post('templates/bibliotheque', [TemplateController::class, 'add'])->name('templates.add');
        Route::post('templates/paquet', [TemplateController::class, 'pack'])->name('templates.pack');
        Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    });

    // Retour de Facebook : l'assistant concerne est memorise dans l'etat (state) de la demande.
    Route::get('facebook/callback', [FacebookController::class, 'callback'])->name('facebook.callback');

    Route::get('billing', [BillingController::class, 'show'])->name('billing.show');
    Route::post('billing/request', [BillingController::class, 'request'])->name('billing.request');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/app/installed', [PwaController::class, 'installed'])->name('pwa.installed');
    Route::post('/profile/password-link', [ProfileController::class, 'sendPasswordLink'])->middleware('throttle:5,1')->name('profile.password-link');
    Route::post('/profile/sessions/logout-others', [ProfileController::class, 'logoutOthers'])->middleware('throttle:5,1')->name('profile.logout-others');
    Route::put('/compte/devise', [CurrencyController::class, 'update'])->name('currency.update');

    // Notifications : le centre (cloche, compteur sur l'icône), les préférences, et les appareils qui les reçoivent.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/resume', [NotificationController::class, 'summary'])->name('notifications.summary');
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');
    Route::put('/notifications/preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
    Route::post('/notifications/tout-lire', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{notification}/ouvrir', [NotificationController::class, 'open'])->whereNumber('notification')->name('notifications.open');
    Route::post('/notifications/{notification}/lu', [NotificationController::class, 'read'])->whereNumber('notification')->name('notifications.read');
    Route::post('/push/abonnement', [PushController::class, 'subscribe'])->middleware('throttle:30,1')->name('push.subscribe');
    Route::delete('/push/abonnement', [PushController::class, 'unsubscribe'])->middleware('throttle:30,1')->name('push.unsubscribe');
    Route::post('/push/essai', [PushController::class, 'test'])->middleware('throttle:6,1')->name('push.test');
    Route::delete('/push/appareils/{subscription}', [PushController::class, 'destroy'])->whereNumber('subscription')->name('push.devices.destroy');
});

/*
|--------------------------------------------------------------------------
| Espace du personnel : admin et super admin
|--------------------------------------------------------------------------
*/
// Guides internes du personnel (le guide du super admin est réservé au super admin).
Route::middleware(['auth', 'staff'])->group(function () {
    Route::get('/admin/guides/{guide}', [StaffGuideController::class, 'show'])->name('staff.guide');
    Route::get('/admin/guides/{guide}/pdf', [StaffGuideController::class, 'pdf'])->name('staff.guide.pdf');
});

Route::middleware(['auth', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\OverviewController::class)->name('overview');

    Route::get('workspaces', [Admin\WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::get('workspaces/create', [Admin\WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('workspaces', [Admin\WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::get('workspaces/{workspace}', [Admin\WorkspaceController::class, 'show'])->name('workspaces.show');
    Route::put('workspaces/{workspace}', [Admin\WorkspaceController::class, 'update'])->name('workspaces.update');
    Route::post('workspaces/{workspace}/enter', [Admin\WorkspaceController::class, 'enter'])->name('workspaces.enter');
    Route::post('leave', [Admin\WorkspaceController::class, 'leave'])->name('workspaces.leave');
    Route::post('workspaces/{workspace}/suspend', [Admin\WorkspaceController::class, 'suspend'])->name('workspaces.suspend');
    Route::put('workspaces/{workspace}/plan', [Admin\WorkspaceController::class, 'plan'])->name('workspaces.plan');
    Route::post('workspaces/{workspace}/payments', [Admin\PaymentController::class, 'store'])->name('payments.store');
    Route::post('workspaces/{workspace}/wallet', [Admin\WalletController::class, 'topup'])->name('wallet.topup');
    Route::post('workspaces/{workspace}/addons', [Admin\WorkspaceController::class, 'addon'])->name('workspaces.addon');
    Route::post('workspaces/{workspace}/users', [Admin\WorkspaceUserController::class, 'store'])->name('workspace-users.store');
    Route::post('users/{user}/reset', [Admin\WorkspaceUserController::class, 'reset'])->name('users.reset');
    Route::post('users/{user}/toggle', [Admin\WorkspaceUserController::class, 'toggle'])->name('users.toggle');

    Route::get('channel-requests', [Admin\ChannelRequestController::class, 'index'])->name('requests.index');
    Route::get('channel-requests/{channelRequest}', [Admin\ChannelRequestController::class, 'show'])->name('requests.show');
    Route::put('channel-requests/{channelRequest}', [Admin\ChannelRequestController::class, 'update'])->name('requests.update');
    Route::post('channels/{channel}/test', [Admin\ChannelRequestController::class, 'test'])->name('channels.test');

    // Notifications envoyées aux clients (promotions, nouveautés, messages importants) : ouvert à l'équipe, consigné dans le journal.
    Route::get('notifications', [Admin\NotificationCampaignController::class, 'index'])->name('notifications.index');
    Route::get('notifications/nouvelle', [Admin\NotificationCampaignController::class, 'create'])->name('notifications.create');
    Route::post('notifications', [Admin\NotificationCampaignController::class, 'store'])->name('notifications.store');
    Route::post('notifications/audience', [Admin\NotificationCampaignController::class, 'estimate'])->name('notifications.estimate');
    Route::post('notifications/essai', [Admin\NotificationCampaignController::class, 'test'])->middleware('throttle:10,1')->name('notifications.test');
    Route::get('notifications/{campaign}', [Admin\NotificationCampaignController::class, 'show'])->whereNumber('campaign')->name('notifications.show');
    Route::get('notifications/{campaign}/modifier', [Admin\NotificationCampaignController::class, 'edit'])->whereNumber('campaign')->name('notifications.edit');
    Route::put('notifications/{campaign}', [Admin\NotificationCampaignController::class, 'update'])->whereNumber('campaign')->name('notifications.update');
    Route::post('notifications/{campaign}/annuler', [Admin\NotificationCampaignController::class, 'cancel'])->whereNumber('campaign')->name('notifications.cancel');

    Route::get('plan-requests', [Admin\PlanRequestController::class, 'index'])->name('plan-requests.index');
    Route::put('plan-requests/{planRequest}', [Admin\PlanRequestController::class, 'update'])->name('plan-requests.update');

    /*
    | Reglages sensibles : super admin uniquement.
    */
    Route::middleware('superadmin')->group(function () {
        Route::get('plans', [Admin\PlanController::class, 'index'])->name('plans.index');
        Route::get('plans/create', [Admin\PlanController::class, 'create'])->name('plans.create');
        Route::post('plans', [Admin\PlanController::class, 'store'])->name('plans.store');
        Route::get('plans/{plan}/edit', [Admin\PlanController::class, 'edit'])->name('plans.edit');
        Route::put('plans/{plan}', [Admin\PlanController::class, 'update'])->name('plans.update');
        Route::delete('plans/{plan}', [Admin\PlanController::class, 'destroy'])->name('plans.destroy');

        Route::get('consumption', [Admin\ConsumptionController::class, 'index'])->name('consumption.index');
        Route::get('consumption/live', [Admin\ConsumptionController::class, 'live'])->name('consumption.live');
        Route::post('consumption/wallet', [Admin\ConsumptionController::class, 'refreshWallet'])->name('consumption.wallet');
        Route::get('ai', [Admin\ProviderController::class, 'index'])->name('ai.index');
        Route::post('ai/providers', [Admin\ProviderController::class, 'store'])->name('ai.store');
        Route::put('ai/providers/{provider}', [Admin\ProviderController::class, 'update'])->name('ai.update');
        Route::delete('ai/providers/{provider}', [Admin\ProviderController::class, 'destroy'])->name('ai.destroy');
        Route::post('ai/providers/{provider}/test', [Admin\ProviderController::class, 'test'])->name('ai.test');
        Route::post('ai/providers/{provider}/reset', [Admin\ProviderController::class, 'reset'])->name('ai.reset');
        Route::put('ai/mode', [Admin\ProviderController::class, 'mode'])->name('ai.mode');
        Route::put('ai/embeddings', [Admin\ProviderController::class, 'embeddings'])->name('ai.embeddings');
        Route::post('ai/reindex', [Admin\ProviderController::class, 'reindex'])->name('ai.reindex');

        Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/voice-test', [Admin\SettingsController::class, 'voiceTest'])->name('settings.voice-test');

        Route::get('team', [Admin\TeamController::class, 'index'])->name('team.index');
        Route::post('team', [Admin\TeamController::class, 'store'])->name('team.store');
        Route::put('team/{user}', [Admin\TeamController::class, 'update'])->name('team.update');

        Route::get('audit', [Admin\AuditController::class, 'index'])->name('audit.index');

        // Aperçu de tous les e-mails de la plateforme, et essai d'envoi à soi-même.
        Route::get('emails', [Admin\MailPreviewController::class, 'index'])->name('emails.index');
        Route::get('emails/{key}', [Admin\MailPreviewController::class, 'show'])->where('key', '[A-Za-z-]+')->name('emails.show');
        Route::post('emails/{key}/envoyer', [Admin\MailPreviewController::class, 'send'])->where('key', '[A-Za-z-]+')->middleware('throttle:10,1')->name('emails.send');

        // Statistiques : visites, comportements, clients (voir docs/ANALYTICS.md).
        Route::get('statistiques', [Admin\StatisticsController::class, 'index'])->name('statistics.index');
        Route::get('statistiques/direct', [Admin\StatisticsController::class, 'live'])->name('statistics.live');
        Route::get('statistiques/export/{table}', [Admin\StatisticsController::class, 'export'])->name('statistics.export');
    });
});

require __DIR__.'/auth.php';
