<?php

namespace App\Support;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Values shown in the shared footer. Every lookup degrades to a safe default so the
 * footer can render on error pages, even when the database is unavailable.
 */
class FooterDetails
{
    public function organisationName(): string
    {
        try {
            $organisationName = app(SettingsService::class)->get('organisation.name');
        } catch (Throwable) {
            $organisationName = null;
        }

        return is_string($organisationName) && trim($organisationName) !== ''
            ? $organisationName
            : (string) config('app.name', 'AquaOps');
    }

    public function version(): string
    {
        return (string) config('app.version', '0.1.0');
    }

    public function year(): int
    {
        return (int) now()->format('Y');
    }

    public function helpUrl(): ?string
    {
        return Route::has('help') ? route('help') : null;
    }
}
