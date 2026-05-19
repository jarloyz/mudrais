<?php

use App\Http\Controllers\Api\Voice\VoiceAssetController;
use App\Http\Controllers\Api\Voice\VoiceInterviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/voice')
    ->middleware('voice.bridge')
    ->group(function (): void {
        // Endpoints de control: límite conservador.
        Route::middleware('throttle:60,1')->group(function (): void {
            Route::post('/session/start', [VoiceInterviewController::class, 'startSession']);
            Route::post('/transcription', [VoiceInterviewController::class, 'handleTranscription']);
        });

        // Endpoints de polling de alta frecuencia: hasta 600 req/min (10/s).
        // pollNextQuestion corre cada 500ms; pollPendingStart cada 2s — necesitan margen amplio.
        Route::middleware('throttle:600,1')->group(function (): void {
            Route::get('/next-question/{sessionId}', [VoiceInterviewController::class, 'pollNextQuestion']);
            Route::get('/pending-start', [VoiceInterviewController::class, 'pollPendingStart']);
        });

        // Assets: sin throttle — se descargan al inicio de sesión, no en bucle.
        Route::get('/assets/{archetypeId}/{filename}', [VoiceAssetController::class, 'serve']);
    });
