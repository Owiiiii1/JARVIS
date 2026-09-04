<?php

use App\Http\Controllers\CabinetChatController;
use App\Http\Controllers\CabinetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\Jarvis\JarvisConfirmationController;
use App\Http\Controllers\Jarvis\JarvisWorkspaceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\GitHubOAuthController;
use App\Http\Controllers\Settings\GoogleOAuthController;
use App\Http\Controllers\Settings\IntegrationsController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\TelegramSettingsController;
use App\Http\Controllers\Settings\UserController as SettingsUserController;
use App\Http\Controllers\Settings\UserMemoryController;
use App\Http\Controllers\TelegramGroupController;
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

Route::middleware(['web', 'auth', 'user.active', 'cabinet.owner.redirect'])->group(function () {
    Route::get('/cabinet', [CabinetController::class, 'index'])->name('cabinet.index');
    Route::get('/cabinet/ai-settings', [UserAiSettingsController::class, 'edit'])->name('cabinet.ai-settings.edit');
    Route::patch('/cabinet/ai-settings', [UserAiSettingsController::class, 'update'])->name('cabinet.ai-settings.update');
    Route::get('/cabinet/chats/{conversation}', [CabinetChatController::class, 'show'])->name('cabinet.chats.show');
    Route::post('/cabinet/chats', [CabinetChatController::class, 'store'])->name('cabinet.chats.store');
    Route::patch('/cabinet/chats/{conversation}', [CabinetChatController::class, 'update'])->name('cabinet.chats.update');
    Route::get('/cabinet/chats/{conversation}/messages', [CabinetChatController::class, 'messages'])->name('cabinet.chats.messages.index');
    Route::post('/cabinet/chats/{conversation}/messages', [CabinetChatController::class, 'storeMessage'])->name('cabinet.chats.messages.store');
});

Route::middleware(['web', 'auth', 'user.active', 'owner.workspace'])->group(function () {
    Route::get('/jarvis', [JarvisWorkspaceController::class, 'index'])->name('jarvis.index');
    Route::post('/jarvis/chats', [JarvisWorkspaceController::class, 'store'])->name('jarvis.chats.store');
    Route::get('/jarvis/chats/{conversation}', [JarvisWorkspaceController::class, 'show'])->name('jarvis.chats.show');
    Route::patch('/jarvis/chats/{conversation}', [JarvisWorkspaceController::class, 'update'])->name('jarvis.chats.update');
    Route::post('/jarvis/chats/{conversation}/messages', [JarvisWorkspaceController::class, 'storeMessage'])->name('jarvis.messages.store');
    Route::get('/jarvis/chats/{conversation}/messages/older', [JarvisWorkspaceController::class, 'olderMessages'])->name('jarvis.messages.older');
    Route::post('/jarvis/confirmations/{confirmation}/confirm', [JarvisConfirmationController::class, 'confirm'])->name('jarvis.confirmations.confirm');
    Route::post('/jarvis/confirmations/{confirmation}/cancel', [JarvisConfirmationController::class, 'cancel'])->name('jarvis.confirmations.cancel');
    Route::patch('/jarvis/settings/general-prompt', [JarvisWorkspaceController::class, 'updateGeneralPrompt'])->name('jarvis.settings.prompt.update');
});

Route::middleware(array_merge(AdminRouteMiddleware::stack(), ['user.active', 'owner']))->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('/telegram-groups', [TelegramGroupController::class, 'index'])->name('telegram-groups.index');
    Route::get('/telegram-groups/archive', [TelegramGroupController::class, 'archive'])->name('telegram-groups.archive');
    Route::get('/telegram-groups/{telegramGroup}', [TelegramGroupController::class, 'show'])->name('telegram-groups.show');
    Route::patch('/telegram-groups/{telegramGroup}', [TelegramGroupController::class, 'update'])->name('telegram-groups.update');
    Route::get('/telegram-groups/{telegramGroup}/messages', [TelegramGroupController::class, 'messages'])->name('telegram-groups.messages.index');
    Route::post('/telegram-groups/{telegramGroup}/messages', [TelegramGroupController::class, 'storeMessage'])->name('telegram-groups.messages.store');
    Route::post('/telegram-groups/{telegramGroup}/analysis', [TelegramGroupController::class, 'storeAnalysis'])->name('telegram-groups.analysis.store');
    Route::post('/telegram-groups/{telegramGroup}/analysis-runs/{run}/retry', [TelegramGroupController::class, 'retryAnalysis'])->name('telegram-groups.analysis.retry');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('/projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('/projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');
    Route::post('/projects/{project}/conversations', [ProjectController::class, 'attachConversation'])->name('projects.conversations.store');
    Route::delete('/projects/{project}/conversations/{conversation}', [ProjectController::class, 'detachConversation'])->name('projects.conversations.destroy');
    Route::post('/projects/{project}/topics', [ProjectController::class, 'attachTopic'])->name('projects.topics.store');
    Route::delete('/projects/{project}/topics/{topic}', [ProjectController::class, 'detachTopic'])->name('projects.topics.destroy');
    Route::post('/projects/{project}/memories', [ProjectController::class, 'attachMemory'])->name('projects.memories.store');
    Route::delete('/projects/{project}/memories/{memory}', [ProjectController::class, 'detachMemory'])->name('projects.memories.destroy');
    Route::post('/projects/{project}/groups', [ProjectController::class, 'attachGroup'])->name('projects.groups.store');
    Route::delete('/projects/{project}/groups/{telegramGroup}', [ProjectController::class, 'detachGroup'])->name('projects.groups.destroy');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/integrations', [IntegrationsController::class, 'index'])->name('settings.integrations.index');
    Route::get('/settings/integrations/accounts/{integrationAccount}', [IntegrationsController::class, 'showAccount'])->name('settings.integrations.accounts.show');
    Route::get('/settings/integrations/google/connect', [GoogleOAuthController::class, 'connect'])
        ->middleware('throttle:10,1')
        ->name('integrations.google.connect');
    Route::post('/settings/integrations/google/disconnect', [GoogleOAuthController::class, 'disconnect'])
        ->name('integrations.google.disconnect');
    Route::get('/integrations/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('integrations.google.callback');
    Route::get('/settings/integrations/github/connect', [GitHubOAuthController::class, 'connect'])
        ->middleware('throttle:10,1')
        ->name('integrations.github.connect');
    Route::post('/settings/integrations/github/disconnect', [GitHubOAuthController::class, 'disconnect'])
        ->name('integrations.github.disconnect');
    Route::get('/integrations/github/callback', [GitHubOAuthController::class, 'callback'])
        ->name('integrations.github.callback');
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
