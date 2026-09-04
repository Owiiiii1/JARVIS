<?php

namespace App\Http\Controllers;

use App\Services\Users\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function stop(Request $request, ImpersonationService $impersonation): RedirectResponse
    {
        $owner = $impersonation->stop($request);

        if ($owner === null) {
            return redirect()->route('login');
        }

        return redirect()->route('jarvis.index');
    }
}
