<?php

declare(strict_types=1);

namespace App\Services;

class ToastService
{
    /**
     * Show session expired toast notification
     */
    public static function sessionExpired(?string $customMessage = null): void
    {
        $message = $customMessage ?? 'Your campus session has expired. Please login again.';

        // Flash toast message to session
        session()->flash('toast', [
            'type' => 'warning',
            'message' => $message,
            'duration' => 5000, // 5 seconds
            'icon' => 'exclamation-triangle',
            'action' => [
                'text' => 'Login',
                'url' => '/login',
            ],
        ]);
    }

    /**
     * Show success toast notification
     */
    public static function success(string $message, string $duration = '3000'): void
    {
        session()->flash('toast', [
            'type' => 'success',
            'message' => $message,
            'duration' => intval($duration),
            'icon' => 'check-circle',
        ]);
    }

    /**
     * Show error toast notification
     */
    public static function error(string $message, string $duration = '5000'): void
    {
        session()->flash('toast', [
            'type' => 'error',
            'message' => $message,
            'duration' => intval($duration),
            'icon' => 'x-circle',
        ]);
    }

    /**
     * Show info toast notification
     */
    public static function info(string $message, string $duration = '3000'): void
    {
        session()->flash('toast', [
            'type' => 'info',
            'message' => $message,
            'duration' => intval($duration),
            'icon' => 'information-circle',
        ]);
    }

    /**
     * Show custom toast notification
     */
    public static function show(string $type, string $message, array $options = []): void
    {
        $defaults = [
            'type' => $type,
            'message' => $message,
            'duration' => 3000,
            'icon' => self::getDefaultIcon($type),
            'action' => null,
        ];

        $toastData = array_merge($defaults, $options);
        session()->flash('toast', $toastData);
    }

    /**
     * Get default icon for toast type
     */
    private static function getDefaultIcon(string $type): string
    {
        $icons = [
            'success' => 'check-circle',
            'error' => 'x-circle',
            'warning' => 'exclamation-triangle',
            'info' => 'information-circle',
        ];

        return $icons[$type] ?? 'information-circle';
    }

    /**
     * Check if there's a toast message to display
     */
    public static function has(): bool
    {
        return session()->has('toast');
    }

    /**
     * Get current toast message and clear it
     */
    public static function get(): ?array
    {
        $toast = session('toast');
        session()->forget('toast');

        return $toast;
    }

    /**
     * Get toast without clearing (for view sharing)
     */
    public static function peek(): ?array
    {
        return session('toast');
    }

    /**
     * Clear all toast messages
     */
    public static function clear(): void
    {
        session()->forget('toast');
    }

    /**
     * Show login required toast with redirect
     */
    public static function loginRequired(?string $message = null): void
    {
        self::sessionExpired($message ?? 'Please login to continue.');
    }

    /**
     * Show campus session invalid toast
     */
    public static function campusSessionInvalid(?string $message = null): void
    {
        self::sessionExpired($message ?? 'Your campus session is invalid. Please login again.');
    }

    /**
     * Show scraping error toast
     */
    public static function scrapingError(?string $message = null): void
    {
        self::error($message ?? 'Failed to fetch data from campus portal. Please try again.');
    }

    /**
     * Show data saved toast
     */
    public static function dataSaved(?string $message = null): void
    {
        self::success($message ?? 'Data saved successfully.');
    }

    /**
     * Show data loaded toast
     */
    public static function dataLoaded(?string $message = null): void
    {
        self::success($message ?? 'Data loaded successfully.');
    }

    /**
     * Show validation error toast
     */
    public static function validationError(?string $message = null): void
    {
        self::error($message ?? 'Please check your input and try again.');
    }

    /**
     * Show network error toast
     */
    public static function networkError(?string $message = null): void
    {
        self::error($message ?? 'Network error. Please check your connection and try again.');
    }
}
