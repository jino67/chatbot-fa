<?php

use App\Http\Controllers\Api\DeveloperController;
use App\Http\Controllers\Api\WidgetController;
use Illuminate\Support\Facades\Route;

/*
| API publique du widget de chat integre sur les sites des clients.
| Pas de session ni de cookie : la cle publique de l'assistant + le token de conversation suffisent.
*/
Route::prefix('v1/widget/{publicKey}')
    ->middleware(['throttle:widget', 'widget.bot'])
    ->group(function () {
        Route::get('config', [WidgetController::class, 'config']);
        Route::post('conversations', [WidgetController::class, 'start']);
        Route::post('conversations/{token}/messages', [WidgetController::class, 'send']);
        Route::get('conversations/{token}/messages', [WidgetController::class, 'poll']);
        Route::post('conversations/{token}/voice', [WidgetController::class, 'voice']);
        Route::post('conversations/{token}/image', [WidgetController::class, 'image']);
        Route::post('conversations/{token}/speak', [WidgetController::class, 'speak']);
        Route::post('conversations/{token}/close', [WidgetController::class, 'close']);
        Route::post('conversations/{token}/messages/{message}/feedback', [WidgetController::class, 'feedback'])->whereNumber('message');
    });

/*
| API des developpeurs (offre API) : cle secrete par assistant, « Authorization: Bearer kma_... ».
*/
Route::prefix('v1')->middleware(['throttle:api', 'api.key'])->group(function () {
    Route::post('chat', [DeveloperController::class, 'chat']);
    Route::get('usage', [DeveloperController::class, 'usage']);
});
