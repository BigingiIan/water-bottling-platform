<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsSet
{
    /**
     * Handle an incoming request.
     *
     * Suspended users have every session invalidated and are signed out.
     * Users flagged with must_change_password may only visit the password
     * setup page (and sign out) until the flag is cleared.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $fresh = $user->fresh() ?? $user;

        if ($fresh->status === 'suspended') {
            DB::table('sessions')->where('user_id', $fresh->getKey())->delete();

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('auth.failed'),
            ]);
        }

        if ($fresh->must_change_password && ! $request->routeIs('password.set*', 'logout', 'impersonation.*')) {
            return redirect()->route('password.set');
        }

        return $next($request);
    }
}
