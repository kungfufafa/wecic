<?php

declare(strict_types=1);

use App\Http\Controllers\WebLoginController;
use Illuminate\Support\Facades\Route;

// API routes for campus authentication (without CSRF protection)
Route::prefix('campus')->group(function () {
    Route::post('login', [WebLoginController::class, 'login']);
});