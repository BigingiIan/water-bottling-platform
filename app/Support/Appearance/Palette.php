<?php

namespace App\Support\Appearance;

/**
 * The colour profiles in config/appearance.php, as resolved token maps and the CSS that applies them.
 */
class Palette
{
    public const MODES = ['light', 'dark'];

    /**
     * @return array<string, string> profile slug => label
     */
    public function profiles(): array
    {
        return array_map(fn (array $profile): string => $profile['label'], config('appearance.profiles', []));
    }

    /**
     * @return array<string, string> theme slug => label
     */
    public function themes(): array
    {
        return config('appearance.themes', []);
    }

    public function hasProfile(string $profile): bool
    {
        return array_key_exists($profile, config('appearance.profiles', []));
    }

    public function hasTheme(string $theme): bool
    {
        return array_key_exists($theme, $this->themes());
    }

    /**
     * Every token for one profile and mode: the shared base with the profile's own values on top.
     *
     * @return array<string, string> token => colour
     */
    public function tokens(string $profile, string $mode): array
    {
        $profile = $this->hasProfile($profile) ? $profile : config('appearance.default_profile');

        return array_merge(
            config("appearance.base.{$mode}", []),
            config("appearance.profiles.{$profile}.{$mode}", []),
        );
    }

    /**
     * Custom property declarations for a profile and mode, for a style block or a style attribute.
     */
    public function declarations(string $profile, string $mode): string
    {
        $declarations = ["color-scheme: {$mode}"];

        foreach ([...$this->tokens($profile, $mode), ...config('appearance.brand', [])] as $token => $colour) {
            $declarations[] = "--c-{$token}: {$colour}";
        }

        return implode('; ', $declarations).';';
    }

    /**
     * The stylesheet for the page root. "system" carries both modes and lets the browser pick before first paint.
     */
    public function rootStylesheet(string $profile, string $theme): string
    {
        if ($theme === 'system') {
            return ':root { '.$this->declarations($profile, 'light').' } '
                .'@media (prefers-color-scheme: dark) { :root { '.$this->declarations($profile, 'dark').' } }';
        }

        return ':root { '.$this->declarations($profile, $theme === 'dark' ? 'dark' : 'light').' }';
    }
}
