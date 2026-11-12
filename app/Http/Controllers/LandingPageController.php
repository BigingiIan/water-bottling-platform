<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserPreferenceService;
use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    /**
     * Guests see the public landing page; anyone signed in goes straight to their landing page.
     */
    public function __invoke(Request $request, Branding $branding, UserPreferenceService $preferences): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return redirect()->to($preferences->landingUrl($user));
        }

        return view('welcome', [
            'organisationName' => $branding->organisationName(),
            'logoUrl' => $branding->logoUrl(),
        ]);
    }
}
