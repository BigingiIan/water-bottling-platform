<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    public function __construct(private ModuleAccess $moduleAccess) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $this->moduleAccess->authorize($user, $module, ! $request->isMethodSafe());

        return $next($request);
    }
}
