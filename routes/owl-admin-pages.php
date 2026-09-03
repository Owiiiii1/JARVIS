<?php

use App\Http\Controllers\CabinetChatController;
use App\Http\Controllers\CabinetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\TelegramSettingsController;
use App\Http\Controllers\Settings\UserController as SettingsUserController;
use App\Http\Controllers\Settings\UserMemoryController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\UserAiSettingsController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use OwlSolutions\CustomAdminKit\Support\AdminRouteMiddleware;

/*
| Admin preset pages (v0.5).
| Loaded from routes/web.php via:
| require __DIR__.'/owl-admin-pages.php';
*/

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->withoutMiddleware([
        PreventRequestForgery::class,
        ValidateCsrfToken::class,
    ])
    ->name('telegram.webhook');

Route::middleware(['web', 'auth', 'user.active'])->group(function () {
    Route::get('/cabinet', [CabinetController::class, 'index'])->name('cabinet.index');
    Route::get('/cabinet/ai-settings', [UserAiSettingsController::class, 'edit'])->name('cabinet.ai-settings.edit');
    Route::patch('/cabinet/ai-settings', [UserAiSettingsController::class, 'update'])->name('cabinet.ai-settings.update');
    Route::get('/cabinet/chats/{conversation}', [CabinetChatController::class, 'show'])->name('cabinet.chats.show');
    Route::post('/cabinet/chats', [CabinetChatController::class, 'store'])->name('cabinet.chats.store');
    Route::patch('/cabinet/chats/{conversation}', [CabinetChatController::class, 'update'])->name('cabinet.chats.update');
    Route::get('/cabinet/chats/{conversation}/messages', [CabinetChatController::class, 'messages'])->name('cabinet.chats.messages.index');
    Route::post('/cabinet/chats/{conversation}/messages', [CabinetChatController::class, 'storeMessage'])->name('cabinet.chats.messages.store');
});

Route::middleware(array_merge(AdminRouteMiddleware::stack(), ['user.active', 'owner']))->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/language', [SettingsController::class, 'updateLanguage'])->name('settings.language.update');
    Route::post('/settings/users', [SettingsUserController::class, 'store'])->name('settings.users.store');
    Route::get('/settings/users/{user}/memory', [UserMemoryController::class, 'show'])->name('settings.users.memory.show');
    Route::patch('/settings/users/{user}', [SettingsUserController::class, 'update'])->name('settings.users.update');
    Route::delete('/settings/users/{user}', [SettingsUserController::class, 'destroy'])->name('settings.users.destroy');
    Route::post('/settings/users/{user}/telegram/unlink', [SettingsUserController::class, 'unlinkTelegram'])->name('settings.users.telegram.unlink');
    Route::post('/settings/users/{user}/access-code/regenerate', [SettingsUserController::class, 'regenerateAccessCode'])->name('settings.users.access-code.regenerate');

    Route::post('/settings/telegram/save-token', [TelegramSettingsController::class, 'saveToken'])
        ->name('settings.telegram.save-token');
    Route::post('/settings/telegram/check', [TelegramSettingsController::class, 'check'])
        ->name('settings.telegram.check');
    Route::post('/settings/telegram/set-webhook', [TelegramSettingsController::class, 'setWebhook'])
        ->name('settings.telegram.set-webhook');
    Route::post('/settings/telegram/remove-webhook', [TelegramSettingsController::class, 'removeWebhook'])
        ->name('settings.telegram.remove-webhook');

    Route::get('/app-settings', function () {
        return redirect()->route('settings.index', ['tab' => 'app']);
    })->name('app-settings.index');

    Route::get('/ai-settings', [AiSettingsController::class, 'index'])->name('ai-settings.index');
    Route::post('/ai-settings/{provider}/key', [AiSettingsController::class, 'saveKey'])->name('ai-settings.save-key');
    Route::post('/ai-settings/{provider}/check', [AiSettingsController::class, 'check'])->name('ai-settings.check');
    Route::post('/ai-settings/{provider}/activate', [AiSettingsController::class, 'activate'])->name('ai-settings.activate');
    Route::post('/ai-settings/deactivate', [AiSettingsController::class, 'deactivate'])->name('ai-settings.deactivate');
    Route::patch('/ai-settings/roles/{roleKey}', [AiSettingsController::class, 'updateRole'])->name('ai-settings.roles.update');

    Route::get('/statistics/logs', function () {
        return Inertia::render('Statistics/Logs');
    })->name('statistics.logs');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
