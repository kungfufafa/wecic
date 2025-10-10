<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Home page - redirect to login or dashboard
Route::get('/', function () {
    if (session('campus_session_token')) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
})->name('home');

// Login page
Route::get('/login', function () {
    if (session('campus_session_token')) {
        return redirect()->route('dashboard');
    }
    return view('auth.login');
})->name('login');

// Handle login POST request
Route::post('/login', [\App\Http\Controllers\WebLoginController::class, 'login'])->name('login.post');

// Dashboard (protected by campus session middleware)
Route::middleware(['campus.session'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::post('/logout', function () {
        session()->forget(['campus_session_token', 'campus_user_info']);
        return redirect()->route('login')->with('success', 'Anda telah logout.');
    })->name('logout');
});

// API routes for campus data (protected by campus session middleware)
Route::prefix('api/campus')->middleware(['campus.session'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\API\CampusDataController::class, 'dashboard']);
});

// Public API routes for authentication
Route::prefix('api/campus')->group(function () {
    Route::post('/login', [\App\Http\Controllers\API\CampusAuthController::class, 'login']);
    Route::get('/check-session', [\App\Http\Controllers\API\CampusAuthController::class, 'checkSession']);
    Route::post('/refresh-session', [\App\Http\Controllers\API\CampusAuthController::class, 'refreshSession']);
    Route::post('/logout', [\App\Http\Controllers\API\CampusAuthController::class, 'logout']);
});
