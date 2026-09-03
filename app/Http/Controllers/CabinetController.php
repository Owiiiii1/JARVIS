<?php

namespace App\Http\Controllers;

use App\Services\Conversations\ConversationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CabinetController extends Controller
{
    public function index(Request $request, ConversationService $conversations): Response
    {
        $user = $request->user();

        $conversationRows = $conversations->listForUser($user, 50)
            ->map(static fn ($conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Cabinet/Index', [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'status' => $user->status->value,
                'timezone' => $user->timezone,
            ],
            'conversations' => $conversationRows,
        ]);
    }
}
