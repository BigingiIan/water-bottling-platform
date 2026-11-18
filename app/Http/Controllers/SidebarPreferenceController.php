<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SidebarPreferenceController extends Controller
{
    /**
     * Saves the sidebar rail and navigation group state, so the layout renders it the same way on any device.
     */
    public function update(Request $request, UserPreferenceService $preferences): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'collapsed' => ['sometimes', 'boolean'],
            'group' => ['sometimes', 'string', Rule::in(UserPreferenceService::SIDEBAR_GROUPS)],
            'group_collapsed' => ['required_with:group', 'boolean'],
        ]);

        if (array_key_exists('collapsed', $validated)) {
            $preferences->saveSidebarCollapsed($user, (bool) $validated['collapsed']);
        }

        if (array_key_exists('group', $validated)) {
            $preferences->saveSidebarGroup($user, $validated['group'], (bool) $validated['group_collapsed']);
        }

        return response()->noContent();
    }
}
