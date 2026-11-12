<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandingLogoController extends Controller
{
    /**
     * Public, because the landing page shows the logo before anyone signs in.
     */
    public function __invoke(Branding $branding): StreamedResponse
    {
        $path = $branding->logoPath();

        abort_if($path === null, 404);

        return Storage::disk($branding->diskName())->response($path, null, [
            'Cache-Control' => 'public, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
