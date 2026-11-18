<?php

namespace Tests\Feature;

use App\Support\Appearance\Contrast;
use App\Support\Appearance\Palette;
use Tests\TestCase;

class AppearanceContrastTest extends TestCase
{
    public function test_every_token_pair_meets_wcag_aa_in_every_profile_and_mode(): void
    {
        $palette = new Palette;
        $failures = [];
        $checked = 0;

        foreach (array_keys($palette->profiles()) as $profile) {
            foreach (Palette::MODES as $mode) {
                $tokens = $palette->tokens($profile, $mode);

                foreach (['text' => Contrast::TEXT_MINIMUM, 'ui' => Contrast::UI_MINIMUM] as $kind => $minimum) {
                    foreach (config("appearance.contrast_pairs.{$kind}") as [$foreground, $background]) {
                        $ratio = Contrast::ratio($tokens[$foreground], $tokens[$background]);
                        $checked++;

                        if ($ratio < $minimum) {
                            $failures[] = sprintf('%s/%s: %s on %s is %.2f:1, needs %.1f:1', $profile, $mode, $foreground, $background, $ratio, $minimum);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $failures);
        $this->assertGreaterThan(500, $checked);
    }

    public function test_every_profile_defines_the_same_tokens_in_light_and_dark(): void
    {
        $palette = new Palette;
        $expected = array_keys($palette->tokens('ocean', 'light'));
        sort($expected);

        $this->assertSame(['ocean', 'forest', 'slate', 'sunset', 'high-contrast'], array_keys($palette->profiles()));

        foreach (array_keys($palette->profiles()) as $profile) {
            foreach (Palette::MODES as $mode) {
                $tokens = config("appearance.profiles.{$profile}.{$mode}") + config("appearance.base.{$mode}");
                $names = array_keys($tokens);
                sort($names);

                $this->assertSame($expected, $names, "{$profile}/{$mode} defines a different token set.");

                foreach ($tokens as $token => $colour) {
                    $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}([0-9a-f]{2})?$/', $colour, "{$profile}/{$mode} {$token}");
                }
            }
        }
    }

    public function test_status_colours_keep_their_hue_in_every_profile_and_mode(): void
    {
        $palette = new Palette;
        $hueRanges = ['success' => [90, 170], 'warning' => [20, 50], 'danger' => [340, 375], 'info' => [190, 215]];

        foreach (array_keys($palette->profiles()) as $profile) {
            foreach (Palette::MODES as $mode) {
                $tokens = $palette->tokens($profile, $mode);

                foreach ($hueRanges as $status => [$from, $to]) {
                    foreach ([$status, "{$status}-ink"] as $token) {
                        $hue = $this->hue($tokens[$token]);
                        $hue = $hue < $from ? $hue + 360 : $hue;

                        $this->assertTrue($hue >= $from && $hue <= $to, "{$profile}/{$mode} {$token} {$tokens[$token]} has hue {$hue}, outside {$status}.");
                    }
                }
            }
        }
    }

    public function test_every_token_is_mapped_to_a_tailwind_colour(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $tokens = [...array_keys((new Palette)->tokens('ocean', 'light')), ...array_keys(config('appearance.brand'))];

        foreach ($tokens as $token) {
            $this->assertStringContainsString("var(--c-{$token});", $css, "--c-{$token} has no Tailwind colour.");
        }

        $this->assertStringContainsString('--color-*: initial;', $css, "Tailwind's own palette must stay removed.");
    }

    private function hue(string $hex): float
    {
        [$red, $green, $blue] = array_map(fn (string $channel): float => hexdec($channel) / 255, str_split(substr($hex, 1, 6), 2));
        $max = max($red, $green, $blue);
        $delta = $max - min($red, $green, $blue);

        if ($delta == 0.0) {
            return 0.0;
        }

        $hue = match ($max) {
            $red => fmod(($green - $blue) / $delta, 6),
            $green => ($blue - $red) / $delta + 2,
            default => ($red - $green) / $delta + 4,
        };

        return fmod($hue * 60 + 360, 360);
    }
}
