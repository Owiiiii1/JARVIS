<?php

namespace App\Http\Controllers;

use App\Services\Conversations\ConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CabinetController extends Controller
{
    public function index(Request $request, ConversationService $conversations): RedirectResponse
    {
        $conversation = $conversations->getOrCreateDefault($request->user());

        return redirect()->route('cabinet.chats.show', $conversation);
    }
}
