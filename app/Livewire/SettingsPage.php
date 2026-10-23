<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\ForecastRecord;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Settings', 'breadcrumbSection' => 'System', 'activeItem' => 'Settings'])]
class SettingsPage extends Component
{
    use WithPagination;

    private const TABS = ['general', 'operations', 'forecasting', 'notifications', 'security', 'audit'];

    #[Url(as: 'tab', except: 'general')]
    public string $activeTab = 'general';

    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public string $auditUser = '';

    public string $auditAction = '';

    public string $auditDateFrom = '';

    public string $auditDateTo = '';

    public bool $showResetModal = false;

    #[Locked]
    public ?string $resetTab = null;

    public function mount(SettingsService $settings): void
    {
        $this->authorizeSettings();

        if (! in_array($this->activeTab, self::TABS, true)) {
            $this->activeTab = 'general';
        }

        $this->fillValues($settings);
    }

    public function updatedActiveTab(SettingsService $settings): void
    {
        if (! in_array($this->activeTab, self::TABS, true)) {
            $this->activeTab = 'general';
        }

        $this->resetValidation();
        $this->fillValues($settings);
    }

    public function updatedAuditUser(): void
    {
        $this->validateOnly('auditUser');
        $this->resetPage(pageName: 'auditPage');
    }

    public function updatedAuditAction(): void
    {
        $this->validateOnly('auditAction');
        $this->resetPage(pageName: 'auditPage');
    }

    public function updatedAuditDateFrom(): void
    {
        $this->validateOnly('auditDateFrom');
        $this->resetPage(pageName: 'auditPage');
    }

    public function updatedAuditDateTo(): void
    {
        $this->validateOnly('auditDateTo');
        $this->resetPage(pageName: 'auditPage');
    }

    public function saveSettings(SettingsService $settings): void
    {
        $user = $this->authorizeSettings(write: true);
        abort_if($this->activeTab === 'audit', 403);

        $values = [];

        foreach ($this->visibleDefinitions($settings) as $key => $definition) {
            if (($definition['readonly'] ?? false) === true) {
                continue;
            }

            $values[$key] = $this->values[$definition['field']] ?? null;
        }

        $settings->setMany($values, $user);
        $this->fillValues($settings);
        $this->resetValidation();
        $this->dispatch('settings-saved', message: 'Settings saved.');
    }

    public function requestResetDefaults(): void
    {
        $this->authorizeSettings(write: true);
        abort_if($this->activeTab === 'audit', 403);

        $this->resetTab = $this->activeTab;
        $this->showResetModal = true;
    }

    public function resetToDefaults(SettingsService $settings): void
    {
        $user = $this->authorizeSettings(write: true);
        abort_if($this->resetTab === null || $this->resetTab === 'audit', 404);

        $keys = [];

        foreach ($this->visibleDefinitions($settings, $this->resetTab) as $key => $definition) {
            if (($definition['readonly'] ?? false) !== true) {
                $keys[] = $key;
            }
        }

        $settings->resetToDefaults($keys, $user);
        $this->activeTab = $this->resetTab;
        $this->fillValues($settings);
        $this->showResetModal = false;
        $this->resetTab = null;
        $this->resetValidation();
        $this->dispatch('settings-saved', message: 'Defaults restored.');
    }

    public function closeResetModal(): void
    {
        $this->showResetModal = false;
        $this->resetTab = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'auditUser' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'auditAction' => ['nullable', 'string', 'max:255'],
            'auditDateFrom' => ['nullable', 'date_format:Y-m-d'],
            'auditDateTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:auditDateFrom'],
        ];
    }

    public function render(SettingsService $settings): View
    {
        $user = $this->authorizeSettings();
        $canWrite = app(ModuleAccess::class)->can($user, 'settings', write: true);
        $definitions = $this->visibleDefinitions($settings);
        $showAudit = $this->activeTab === 'audit';
        $showForecastInfo = $this->activeTab === 'forecasting';
        $auditRows = $showAudit
            ? $this->auditQuery()->with('user:id,name')->orderByDesc('timestamp')->orderByDesc('id')->paginate(app(UserPreferenceService::class)->pageSize($user, 25), pageName: 'auditPage')
            : null;

        return view('livewire.settings-page', [
            'definitions' => $definitions,
            'canWrite' => $canWrite,
            'auditRows' => $auditRows,
            'auditUsers' => $showAudit ? User::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'auditActions' => $showAudit ? DB::table('audit_log')->select('action')->distinct()->orderBy('action')->pluck('action') : collect(),
            'modelVersion' => $showForecastInfo ? $this->modelVersion() : '—',
            'lastForecastRunAt' => $showForecastInfo ? ForecastRecord::query()->max('generated_at') : null,
        ]);
    }

    private function authorizeSettings(bool $write = false): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'settings', write: $write);

        return $user;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function visibleDefinitions(SettingsService $settings, ?string $tab = null): array
    {
        $definitions = $settings->definitionsForTab($tab ?? $this->activeTab);

        if (auth()->user()?->role?->slug !== 'administrator') {
            $definitions = array_filter(
                $definitions,
                fn (array $definition): bool => ($definition['admin_only'] ?? false) !== true,
            );
        }

        return $definitions;
    }

    private function fillValues(SettingsService $settings): void
    {
        $this->values = [];

        foreach ($this->visibleDefinitions($settings) as $key => $definition) {
            $this->values[$definition['field']] = $settings->get($key);
        }
    }

    private function auditQuery(): Builder
    {
        return AuditLog::query()
            ->when($this->auditUser !== '', fn (Builder $query): Builder => $query->where('user_id', $this->auditUser))
            ->when($this->auditAction !== '', fn (Builder $query): Builder => $query->where('action', $this->auditAction))
            ->when($this->auditDateFrom !== '', fn (Builder $query): Builder => $query->whereDate('timestamp', '>=', $this->auditDateFrom))
            ->when($this->auditDateTo !== '', fn (Builder $query): Builder => $query->whereDate('timestamp', '<=', $this->auditDateTo));
    }

    private function modelVersion(): string
    {
        $manifestFiles = glob(base_path('ml/saved_models/*/manifest.json')) ?: [];
        $versions = [];

        foreach ($manifestFiles as $manifestFile) {
            $manifest = json_decode(File::get($manifestFile), true, flags: JSON_THROW_ON_ERROR);
            $version = $manifest['model_version'] ?? null;

            if (is_string($version) && $version !== '') {
                $versions[] = $version;
            }
        }

        if ($versions !== []) {
            sort($versions, SORT_STRING);

            return $versions[array_key_last($versions)];
        }

        return (string) (ForecastRecord::query()->orderByDesc('generated_at')->value('model_version') ?? '—');
    }
}
