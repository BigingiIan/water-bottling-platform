<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\UserPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessRequestController extends Controller
{
    public function store(Request $request, NotificationService $notifications, UserPreferenceService $preferences): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'module' => ['required', 'string', Rule::exists('modules', 'slug')],
        ]);

        $module = Module::query()->where('slug', $validated['module'])->firstOrFail();
        $notifications->accessRequested($user, $module);

        return redirect()
            ->to($preferences->landingUrl($user))
            ->with('status', "Access request for {$module->name} sent to your administrators.");
    }
}
