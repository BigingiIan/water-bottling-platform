<?php

namespace App\Services;

use App\Models\User;
use App\Support\Appearance\Palette;
use Throwable;

/**
 * Decides the theme mode and colour profile a page is drawn in: the user's own choice, then the organisation
 * default from Settings, then the built-in default (Light, Ocean). Unknown stored values fall through.
 */
class AppearanceService
{
    public function __construct(
        private Palette $palette,
        private SettingsService $settings,
        private UserPreferenceService $preferences,
    ) {}

    /**
     * @return array{theme: string, profile: string}
     */
    public function resolve(?User $user): array
    {
        $organisation = $this->organisationDefault();

        $theme = $user === null ? null : $this->preferences->themePreference($user);
        $profile = $user === null ? null : $this->preferences->profilePreference($user);

        return [
            'theme' => $theme !== null && $this->palette->hasTheme($theme) ? $theme : $organisation['theme'],
            'profile' => $profile !== null && $this->palette->hasProfile($profile) ? $profile : $organisation['profile'],
        ];
    }

    /**
     * Falls back to the built-in default when settings can't be read, so public pages still render.
     *
     * @return array{theme: string, profile: string}
     */
    public function organisationDefault(): array
    {
        try {
            $theme = (string) $this->settings->get('appearance.theme', config('appearance.default_theme'));
            $profile = (string) $this->settings->get('appearance.profile', config('appearance.default_profile'));
        } catch (Throwable) {
            $theme = config('appearance.default_theme');
            $profile = config('appearance.default_profile');
        }

        return [
            'theme' => $this->palette->hasTheme($theme) ? $theme : config('appearance.default_theme'),
            'profile' => $this->palette->hasProfile($profile) ? $profile : config('appearance.default_profile'),
        ];
    }
}
