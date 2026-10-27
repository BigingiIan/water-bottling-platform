<?php

namespace App\Support\Dashboard;

use Closure;

/**
 * One dashboard card: who may see it, which Livewire component renders it, and how wide it is.
 */
final readonly class DashboardWidget
{
    public const SIZE_SMALL = 'small';

    public const SIZE_WIDE = 'wide';

    /**
     * @param  string|null  $module  Module slug the widget summarises; null for personal widgets every user may see.
     * @param  'view'|'full'  $minimumAccess
     * @param  class-string  $component
     * @param  int  $importance  Higher first when a role has no default layout.
     * @param  (Closure(): bool)|null  $available  Guards widgets whose feature may not be installed yet.
     */
    public function __construct(
        public string $key,
        public string $title,
        public ?string $module,
        public string $minimumAccess,
        public string $component,
        public string $size,
        public int $importance,
        public bool $administratorOnly = false,
        public ?Closure $available = null,
        public bool $personal = false,
    ) {}

    public function isAvailable(): bool
    {
        return $this->available === null || ($this->available)() === true;
    }

    public function isWide(): bool
    {
        return $this->size === self::SIZE_WIDE;
    }

    /**
     * Whether the widget summarises a department's work, rather than the user's own items such as
     * notifications or requests addressed to them.
     */
    public function summarisesModule(): bool
    {
        return $this->module !== null && ! $this->personal;
    }
}
