<?php

use Illuminate\Support\Facades\Route;

/*
| Core kit routes (v0.1). Load via ServiceProvider when this file exists in the host app.
| Dashboard/auth/domain routes are NOT part of core — register them in the host application.
*/

Route::get('/owl-admin/health', function () {
    return response()->json([
        'status' => 'ok',
        'kit' => \OwlSolutions\CustomAdminKit\Support\PackageVersion::current(),
        'preset' => 'core',
    ]);
})->name('owl-admin.health');
