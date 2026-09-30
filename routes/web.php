<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\FacebookController;
use App\Http\Controllers\InstructionController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\PlaygroundController;
use App\Http\Controllers\ProfileController;
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
Route::get('/conditions', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/confidentialite', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/robots.txt', [LandingController::class, 'robots']);
Route::get('/sitemap.xml', [LandingController::class, 'sitemap']);

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

    Route::resource('bots', BotController::class)->except(['show']);

    Route::prefix('bots/{bot}')->scopeBindings()->group(function () {
        Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
        Route::post('sources', [SourceController::class, 'store'])->name('sources.store');
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

        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::post('templates/sync', [TemplateController::class, 'sync'])->name('templates.sync');
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
});

/*
|--------------------------------------------------------------------------
| Espace du personnel : admin et super admin
|--------------------------------------------------------------------------
*/
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
    Route::post('workspaces/{workspace}/users', [Admin\WorkspaceUserController::class, 'store'])->name('workspace-users.store');
    Route::post('users/{user}/reset', [Admin\WorkspaceUserController::class, 'reset'])->name('users.reset');
    Route::post('users/{user}/toggle', [Admin\WorkspaceUserController::class, 'toggle'])->name('users.toggle');

    Route::get('channel-requests', [Admin\ChannelRequestController::class, 'index'])->name('requests.index');
    Route::get('channel-requests/{channelRequest}', [Admin\ChannelRequestController::class, 'show'])->name('requests.show');
    Route::put('channel-requests/{channelRequest}', [Admin\ChannelRequestController::class, 'update'])->name('requests.update');
    Route::post('channels/{channel}/test', [Admin\ChannelRequestController::class, 'test'])->name('channels.test');

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

        Route::get('team', [Admin\TeamController::class, 'index'])->name('team.index');
        Route::post('team', [Admin\TeamController::class, 'store'])->name('team.store');
        Route::put('team/{user}', [Admin\TeamController::class, 'update'])->name('team.update');

        Route::get('audit', [Admin\AuditController::class, 'index'])->name('audit.index');
    });
});

require __DIR__.'/auth.php';
