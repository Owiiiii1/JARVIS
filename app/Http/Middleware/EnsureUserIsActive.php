<?php

namespace App\Http\Middleware;

use App\Services\Users\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            $impersonation = app(ImpersonationService::class);

            if ($impersonation->isActive($request)) {
                $owner = $impersonation->stop($request);

                if ($owner !== null) {
                    return redirect()
                        ->route('dashboard')
                        ->with('warning', 'That account is not available.');
                }
            }

            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => trans('auth.failed')]);
        }

        return $next($request);
    }
}
