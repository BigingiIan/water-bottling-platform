<?php

namespace App\Livewire;

use App\Models\Module;
use App\Models\User;
use App\Services\Dashboard\AttentionItems;
use App\Support\Dashboard\DashboardQuickActions;
use App\Support\Dashboard\DashboardWidget;
use App\Support\Dashboard\DashboardWidgetRegistry;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Dashboard', 'breadcrumbSection' => 'Home', 'activeItem' => 'Dashboard'])]
class Dashboard extends Component
{
    /**
     * Modules a near-empty role is offered to request access to.
     */
    private const REQUESTABLE_MODULES = ['production', 'inventory', 'orders'];

    public bool $customising = false;

    /**
     * Draft widget order while customising.
     *
     * @var list<string>
     */
    public array $layoutOrder = [];

    /**
     * Draft hidden widget keys while customising.
     *
     * @var list<string>
     */
    public array $layoutHidden = [];

    public function startCustomising(DashboardWidgetRegistry $registry): void
    {
        $user = $this->authorizeDashboard();
        $layout = $registry->layoutFor($user);

        $this->layoutOrder = array_map(fn (array $entry): string => $entry['widget']->key, $layout);
        $this->layoutHidden = array_values(array_map(
            fn (array $entry): string => $entry['widget']->key,
            array_filter($layout, fn (array $entry): bool => ! $entry['visible']),
        ));
        $this->resetValidation();
        $this->customising = true;
    }

    public function moveWidget(string $key, string $direction): void
    {
        $index = array_search($key, $this->layoutOrder, true);
        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || ! isset($this->layoutOrder[$targetIndex])) {
            return;
        }

        [$this->layoutOrder[$index], $this->layoutOrder[$targetIndex]] = [$this->layoutOrder[$targetIndex], $this->layoutOrder[$index]];
    }

    public function toggleWidget(string $key): void
    {
        if (! in_array($key, $this->layoutOrder, true)) {
            return;
        }

        $this->layoutHidden = in_array($key, $this->layoutHidden, true)
            ? array_values(array_diff($this->layoutHidden, [$key]))
            : [...$this->layoutHidden, $key];
    }

    public function saveLayout(DashboardWidgetRegistry $registry): void
    {
        $user = $this->authorizeDashboard();

        $registry->saveLayout(
            $user,
            array_values(array_filter($this->layoutOrder, 'is_string')),
            array_values(array_filter($this->layoutHidden, 'is_string')),
        );

        $this->customising = false;
    }

    public function resetLayout(DashboardWidgetRegistry $registry): void
    {
        $registry->resetLayout($this->authorizeDashboard());
        $this->customising = false;
    }

    public function cancelCustomising(): void
    {
        $this->resetValidation();
        $this->customising = false;
    }

    public function render(DashboardWidgetRegistry $registry, AttentionItems $attentionItems, DashboardQuickActions $quickActions): View
    {
        $user = $this->authorizeDashboard();
        $permittedWidgets = $registry->permittedFor($user);
        $hasModuleWidgets = collect($permittedWidgets)->contains(fn (DashboardWidget $widget): bool => $widget->summarisesModule());
        $accessLevels = $registry->accessLevels($user);

        return view('livewire.dashboard', [
            'user' => $user,
            'widgets' => $registry->visibleFor($user),
            'permittedWidgets' => $permittedWidgets,
            'attentionItems' => $hasModuleWidgets ? $attentionItems->for($user) : [],
            'quickActions' => $quickActions->for($user),
            'hasModuleWidgets' => $hasModuleWidgets,
            'requestableModules' => $hasModuleWidgets
                ? collect()
                : Module::query()
                    ->whereIn('slug', array_filter(
                        self::REQUESTABLE_MODULES,
                        fn (string $slug): bool => ! in_array($accessLevels[$slug] ?? 'none', ['view', 'full'], true),
                    ))
                    ->orderBy('name')
                    ->get(['slug', 'name', 'description']),
        ]);
    }

    private function authorizeDashboard(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'dashboard');

        return $user;
    }
}
