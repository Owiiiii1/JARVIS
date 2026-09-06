<?php

use App\Http\Controllers\Api\ElevenLabsCustomLlmController;
use App\Http\Middleware\AuthenticateElevenLabsCustomLlm;
use Illuminate\Support\Facades\Route;

Route::post('/voice/elevenlabs/chat/completions', [ElevenLabsCustomLlmController::class, 'completions'])
    ->middleware([AuthenticateElevenLabsCustomLlm::class, 'throttle:60,1'])
    ->name('voice.elevenlabs.chat.completions');
