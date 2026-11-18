<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PasswordSetController extends Controller
{
    public function show(Request $request, UserPreferenceService $preferences): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        if (! $user->must_change_password) {
            return redirect()->to($preferences->landingUrl($user));
        }

        $isViewingAs = Impersonation::active();

        if (! $isViewingAs && $user->temporary_password_expires_at?->isPast()) {
            return $this->rejectExpiredTemporaryPassword($request);
        }

        return view('auth.password-set', ['isViewingAs' => $isViewingAs]);
    }

    /**
     * Only a user who still holds a temporary password may set one here; everyone else must use
     * the profile page, which asks for the current password.
     */
    public function update(Request $request, SettingsService $settings, UserPreferenceService $preferences): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_if(Impersonation::active(), 403, 'Passwords cannot be set while using View As.');

        if (! $user->must_change_password) {
            return redirect()->to($preferences->landingUrl($user));
        }

        if ($user->temporary_password_expires_at?->isPast()) {
            return $this->rejectExpiredTemporaryPassword($request);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:'.$settings->get('security.password_min_length', 8), 'confirmed', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if (is_string($value) && Hash::check($value, $user->password_hash)) {
                    $fail('The new password must differ from the temporary password.');
                }
            }],
        ]);

        DB::transaction(function () use ($request, $user, $validated): void {
            $oldStatus = $user->status;

            $user->forceFill([
                'password_hash' => Hash::make($validated['password']),
                'status' => 'active',
                'must_change_password' => false,
                'temporary_password_expires_at' => null,
            ])->save();

            $removedSessions = DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();

            AuditLog::create([
                'user_id' => $user->id,
                'target_user_id' => $user->id,
                'action' => 'user.password_set',
                'table_name' => 'users',
                'old_values' => ['status' => $oldStatus, 'must_change_password' => true],
                'new_values' => [
                    'status' => 'active',
                    'must_change_password' => false,
                    'other_sessions_logged_out' => $removedSessions,
                ],
            ]);
        });

        return redirect()->to($preferences->landingUrl($user));
    }

    private function rejectExpiredTemporaryPassword(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => AuthenticatedSessionController::EXPIRED_TEMPORARY_PASSWORD_MESSAGE,
        ]);
    }
}
