<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UserImpersonationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $administrator = $request->user();

        abort_unless(
            $administrator?->role?->slug === 'administrator'
                && $administrator->status === 'active'
                && ! Impersonation::active(),
            403,
        );

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('status', 'active')
                        ->where('id', '!=', $administrator->getKey()),
                ),
            ],
        ]);

        $targetUser = User::query()->findOrFail($validated['user_id']);

        $request->session()->put(Impersonation::SESSION_KEY, $administrator->getKey());
        Auth::login($targetUser);
        $request->session()->regenerate();

        Impersonation::allowingWrites(fn () => $this->audit('impersonation.started', $administrator, $targetUser));

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $administratorId = $request->session()->get(Impersonation::SESSION_KEY);
        $administrator = User::query()
            ->with('role')
            ->whereKey($administratorId)
            ->where('status', 'active')
            ->first();

        abort_unless($administrator?->role?->slug === 'administrator', 403);

        $targetUser = $request->user();

        $request->session()->forget(Impersonation::SESSION_KEY);
        Auth::login($administrator);
        $request->session()->regenerate();

        if ($targetUser instanceof User) {
            Impersonation::allowingWrites(fn () => $this->audit('impersonation.ended', $administrator, $targetUser));
        }

        return redirect()->route('dashboard');
    }

    private function audit(string $action, User $administrator, User $targetUser): void
    {
        AuditLog::create([
            'user_id' => $administrator->id,
            'target_user_id' => $targetUser->id,
            'action' => $action,
            'table_name' => 'users',
            'old_values' => [],
            'new_values' => [
                'administrator_id' => $administrator->id,
                'administrator_email' => $administrator->email,
                'target_user_id' => $targetUser->id,
                'target_email' => $targetUser->email,
            ],
        ]);
    }
}
