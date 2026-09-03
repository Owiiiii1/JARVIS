<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Calendar/Index', [
            'events' => [],
        ]);
    }
}
