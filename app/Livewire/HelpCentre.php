<?php

namespace App\Livewire;

use App\Models\Module;
use App\Models\User;
use App\Services\Help\HelpGuides;
use App\Support\Dashboard\DashboardWidgetRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Help', 'breadcrumbSection' => 'Account', 'activeItem' => 'Help'])]
class HelpCentre extends Component
{
    #[Url(as: 'guide', except: 'getting-started')]
    public string $guide = 'getting-started';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(HelpGuides $guides): void
    {
        $this->normaliseGuide($guides);
    }

    public function updatedGuide(): void
    {
        $this->normaliseGuide(app(HelpGuides::class));
    }

    public function openGuide(string $guide): void
    {
        $this->guide = $guide;
        $this->search = '';
        $this->normaliseGuide(app(HelpGuides::class));
    }

    public function render(HelpGuides $guides, DashboardWidgetRegistry $registry): View
    {
        $user = $this->currentUser();
        $visibleGuides = $guides->visibleTo($user);
        $accessLevels = $registry->accessLevels($user);

        return view('livewire.help-centre', [
            'user' => $user,
            'generalGuides' => array_filter($visibleGuides, fn (array $guide): bool => $guide['module'] === null),
            'moduleGuides' => array_filter($visibleGuides, fn (array $guide): bool => $guide['module'] !== null),
            'currentGuide' => $visibleGuides[$this->guide] ?? null,
            'searchResults' => trim($this->search) === '' ? null : $guides->search($user, $this->search),
            'capabilities' => Module::query()
                ->orderBy('name')
                ->get(['slug', 'name'])
                ->map(fn (Module $module): array => [
                    'slug' => $module->slug,
                    'name' => $module->name,
                    'level' => $level = in_array($accessLevels[$module->slug] ?? 'none', ['view', 'full'], true) ? $accessLevels[$module->slug] : 'none',
                    'description' => $level === 'none'
                        ? __('help.capabilities.none')
                        : __("help.capabilities.{$module->slug}.{$level}"),
                    'guide' => isset($visibleGuides[$module->slug]) ? $module->slug : null,
                ])
                ->all(),
        ]);
    }

    /**
     * Falls back to Getting started for unknown guides and for guides the user's role can't read.
     */
    private function normaliseGuide(HelpGuides $guides): void
    {
        if (! $guides->canRead($this->currentUser(), $this->guide)) {
            $this->guide = 'getting-started';
        }
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
