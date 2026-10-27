<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\User;
use App\Services\Requests\RequestAccess;
use App\Services\Requests\RequestService;
use App\Services\UserPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessRequestController extends Controller
{
    /**
     * Turns "Request Access" on the 403 page into an access request for administrators to review.
     * Approving it is a change in Roles & Permissions; nothing is granted here.
     */
    public function store(Request $request, RequestService $requests, RequestAccess $access, UserPreferenceService $preferences): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'module' => ['required', 'string', Rule::exists('modules', 'slug')],
            'page' => ['nullable', 'string', 'max:2000'],
        ]);

        $module = Module::query()->where('slug', $validated['module'])->firstOrFail();
        $accessRequest = $requests->requestAccess($user, $module, $validated['page'] ?? null);

        return redirect()
            ->to($access->canView($user, $accessRequest) ? route('requests.show', $accessRequest) : $preferences->landingUrl($user))
            ->with('status', "Access request {$accessRequest->reference} for {$module->name} sent to your administrators.");
    }
}
