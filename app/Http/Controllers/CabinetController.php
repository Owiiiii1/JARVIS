<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CabinetController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->isOwner() ? 'jarvis.index' : 'chat.index');
    }
}
