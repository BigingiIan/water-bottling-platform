<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ApplyConfiguredSessionLifetime
{
    public function __construct(private SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Schema::hasTable('settings')) {
            config(['session.lifetime' => $this->settings->get('security.session_minutes', 120)]);
        }

        return $next($request);
    }
}
