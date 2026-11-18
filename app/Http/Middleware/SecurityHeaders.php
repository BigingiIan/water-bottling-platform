<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Baseline headers for every web response. No Content-Security-Policy is sent, because the pages rely on
     * inline Livewire and Alpine scripts.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    private const STRICT_TRANSPORT_SECURITY = 'max-age=31536000';

    /**
     * A header the response already carries is left alone, so a route can set something stricter.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $request->isSecure()
            ? [...self::HEADERS, 'Strict-Transport-Security' => self::STRICT_TRANSPORT_SECURITY]
            : self::HEADERS;

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
