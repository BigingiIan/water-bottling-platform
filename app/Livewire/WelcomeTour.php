<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Help\WelcomeTourSteps;
use App\Services\UserPreferenceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * First-login welcome tour. It opens on the first page after the password is set, is marked as seen
 * straight away so it only runs once, and can be reopened with the `replay-tour` event.
 */
class WelcomeTour extends Component
{
    public bool $isOpen = false;

    public function mount(UserPreferenceService $preferences): void
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->must_change_password || session()->has('impersonator_id') || $preferences->hasSeenWelcomeTour($user)) {
            return;
        }

        $preferences->markWelcomeTourSeen($user);
        $this->isOpen = true;
    }

    #[On('replay-tour')]
    public function replay(): void
    {
        $this->isOpen = auth()->user() instanceof User;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function render(WelcomeTourSteps $steps): View
    {
        $user = auth()->user();

        return view('livewire.welcome-tour', [
            'steps' => $this->isOpen && $user instanceof User ? $steps->for($user) : [],
        ]);
    }
}
