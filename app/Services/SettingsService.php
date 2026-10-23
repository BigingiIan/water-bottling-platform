<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class SettingsService
{
    /**
     * @var array<string, mixed>
     */
    private array $requestCache = [];

    /**
     * @var array<string, string>|null
     */
    private ?array $overrides = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return config('settings.definitions', []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitionsForTab(string $tab): array
    {
        return array_filter(
            $this->definitions(),
            fn (array $definition): bool => $definition['tab'] === $tab,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }

        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null) {
            return $default;
        }

        $this->overrides ??= Setting::query()->pluck('value', 'key')->all();
        $storedValue = $this->overrides[$key] ?? null;
        $value = $storedValue === null
            ? $definition['default']
            : $this->castValue((string) $storedValue, $definition['type']);

        $this->requestCache[$key] = $value;

        return $value;
    }

    public function set(string $key, mixed $value, User $user): void
    {
        $this->setMany([$key => $value], $user);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, User $user): void
    {
        $validatedValues = $this->validateValues($values);
        $definitions = $this->definitions();
        $changedValues = [];

        DB::transaction(function () use ($validatedValues, $definitions, $user, &$changedValues): void {
            foreach ($validatedValues as $field => $value) {
                $key = $this->keyForField($field);
                $definition = $definitions[$key];
                $newValue = $this->normalizeValue($value, $definition['type']);
                $oldValue = $this->get($key, $definition['default']);

                if ($this->valuesAreEqual($oldValue, $newValue, $definition['type'])) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'group' => $definition['group'],
                        'value' => $this->encodeValue($newValue, $definition['type']),
                        'type' => $definition['type'],
                        'updated_by' => $user->getKey(),
                        'updated_at' => now()->utc(),
                    ],
                );
                AuditLog::query()->create([
                    'user_id' => $user->getKey(),
                    'action' => 'settings.updated',
                    'table_name' => 'settings',
                    'old_values' => ['key' => $key, 'value' => $oldValue],
                    'new_values' => ['key' => $key, 'value' => $newValue],
                ]);

                $changedValues[$key] = $newValue;
            }
        });

        foreach ($changedValues as $key => $value) {
            $this->requestCache[$key] = $value;
            $this->overrides[$key] = $this->encodeValue($value, $definitions[$key]['type']);
        }
    }

    /**
     * @param  array<string>  $keys
     */
    public function resetToDefaults(array $keys, User $user): void
    {
        $definitions = $this->definitions();
        $resetValues = [];

        DB::transaction(function () use ($keys, $definitions, $user, &$resetValues): void {
            foreach (array_unique($keys) as $key) {
                $definition = $definitions[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");
                $setting = Setting::query()->where('key', $key)->first();

                if ($setting === null) {
                    continue;
                }

                $oldValue = $this->get($key, $definition['default']);
                $defaultValue = $this->normalizeValue($definition['default'], $definition['type']);
                $setting->delete();
                AuditLog::query()->create([
                    'user_id' => $user->getKey(),
                    'action' => 'settings.reset',
                    'table_name' => 'settings',
                    'old_values' => ['key' => $key, 'value' => $oldValue],
                    'new_values' => ['key' => $key, 'value' => $defaultValue],
                ]);

                $resetValues[$key] = $defaultValue;
            }
        });

        foreach ($resetValues as $key => $value) {
            $this->requestCache[$key] = $value;
            unset($this->overrides[$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function validateValues(array $values): array
    {
        $definitions = $this->definitions();
        $input = [];
        $rules = [];

        foreach ($values as $key => $value) {
            $definition = $definitions[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");

            if (($definition['readonly'] ?? false) === true) {
                throw new InvalidArgumentException("Setting [{$key}] is read-only.");
            }

            $field = $definition['field'];
            $input['values'][$field] = $value;
            $rules["values.{$field}"] = $definition['rules'];
        }

        $validator = Validator::make($input, $rules);
        $effectiveValues = [];

        foreach ($definitions as $key => $definition) {
            $effectiveValues[$definition['field']] = $this->get($key, $definition['default']);
        }

        foreach (($input['values'] ?? []) as $field => $value) {
            $effectiveValues[$field] = $value;
        }

        $validator->after(function ($validator) use ($effectiveValues, $input): void {
            if (! array_key_exists('leakage_review_percent', $input['values'] ?? [])
                && ! array_key_exists('leakage_investigate_percent', $input['values'] ?? [])) {
                return;
            }

            $review = $effectiveValues['leakage_review_percent'];
            $investigate = $effectiveValues['leakage_investigate_percent'];

            if (is_numeric($review) && is_numeric($investigate) && (float) $investigate <= (float) $review) {
                $validator->errors()->add(
                    'values.leakage_investigate_percent',
                    'The investigate threshold must be greater than the review threshold.',
                );
            }
        });

        return $validator->validate()['values'] ?? [];
    }

    private function keyForField(string $field): string
    {
        foreach ($this->definitions() as $key => $definition) {
            if ($definition['field'] === $field) {
                return $key;
            }
        }

        throw new InvalidArgumentException("Unknown setting field [{$field}].");
    }

    private function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'decimal' => (float) $value,
            'bool' => $value === '1',
            'json' => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    private function normalizeValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'decimal' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value,
            default => (string) $value,
        };
    }

    private function encodeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }

    private function valuesAreEqual(mixed $left, mixed $right, string $type): bool
    {
        return match ($type) {
            'decimal' => (float) $left === (float) $right,
            'json' => $left === $right,
            default => $left === $right,
        };
    }
}
