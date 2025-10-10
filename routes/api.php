<?php

declare(strict_types=1);

use App\Http\Controllers\API\CampusDataController;
use App\Http\Controllers\WebLoginController;
use Illuminate\Support\Facades\Route;

// API routes for campus authentication (without CSRF protection)
Route::prefix('campus')->group(function () {
    Route::post('login', [WebLoginController::class, 'login']);

    // Campus data routes (with authentication middleware)
    Route::middleware(['web'])->group(function () {
        Route::get('dashboard', [CampusDataController::class, 'dashboard']);
        Route::get('profile', [CampusDataController::class, 'profile']);
        Route::get('classes', [CampusDataController::class, 'classes']);
        Route::get('class-detail', [CampusDataController::class, 'classDetail']);
        Route::get('class-detail-by-id', [CampusDataController::class, 'classDetailById']);
        Route::get('meeting-detail', [CampusDataController::class, 'meetingDetail']);
        Route::get('meeting-detail-by-id', [CampusDataController::class, 'meetingDetailById']);
    });
});
