<?php

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
    });
