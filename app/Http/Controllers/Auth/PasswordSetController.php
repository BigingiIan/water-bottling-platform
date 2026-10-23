<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PasswordSetController extends Controller
{
    public function show(): View
    {
        return view('auth.password-set');
    }

    public function update(Request $request, SettingsService $settings, UserPreferenceService $preferences): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:'.$settings->get('security.password_min_length', 8), 'confirmed', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if (is_string($value) && Hash::check($value, $user->password_hash)) {
                    $fail('The new password must differ from the temporary password.');
                }
            }],
        ]);

        $user->forceFill([
            'password_hash' => Hash::make($validated['password']),
            'status' => 'active',
            'must_change_password' => false,
        ])->save();

        return redirect()->to($preferences->landingUrl($user));
    }
}
