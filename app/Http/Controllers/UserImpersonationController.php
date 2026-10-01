<?php

namespace App\Http\Controllers;

use App\Models\User;
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
                && ! $request->session()->has('impersonator_id'),
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

        $request->session()->put('impersonator_id', $administrator->getKey());
        Auth::login($targetUser);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $administratorId = $request->session()->get('impersonator_id');
        $administrator = User::query()
            ->with('role')
            ->whereKey($administratorId)
            ->where('status', 'active')
            ->first();

        abort_unless($administrator?->role?->slug === 'administrator', 403);

        $request->session()->forget('impersonator_id');
        Auth::login($administrator);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
