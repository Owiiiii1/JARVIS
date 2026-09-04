<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectOwnerFromUserWorkspace
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! $user->isOwner()) {
            return $next($request);
        }

        if ($request->routeIs('chat.chats.show')) {
            return redirect()->route('jarvis.chats.show', $request->route('conversation'));
        }

        return redirect()->route('jarvis.index');
    }
}
