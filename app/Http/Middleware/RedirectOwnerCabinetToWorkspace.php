<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectOwnerCabinetToWorkspace
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isOwner()) {
            return $next($request);
        }

        if ($request->routeIs('cabinet.chats.show')) {
            return redirect()->route('jarvis.chats.show', $request->route('conversation'));
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            abort(403, 'Owner Personal Workspace is /jarvis.');
        }

        return redirect()->route('jarvis.index');
    }
}
