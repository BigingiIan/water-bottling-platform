<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

class BlockWritesWhileImpersonating
{
    /**
     * Refuse state-changing HTTP requests while View As is active. Livewire updates are let through here so
     * UI-only actions keep working; the database guard refuses any write they attempt.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            Impersonation::active()
            && ! $request->isMethodSafe()
            && ! $request->routeIs('impersonation.destroy', 'logout', 'default-livewire.update')
            && ! Livewire::isLivewireRequest()
        ) {
            Impersonation::deny();
        }

        return $next($request);
    }
}
