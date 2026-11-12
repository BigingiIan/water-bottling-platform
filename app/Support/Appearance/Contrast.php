<?php

namespace App\Support\Appearance;

use InvalidArgumentException;

/**
 * WCAG 2.x contrast ratio between two opaque sRGB colours.
 */
class Contrast
{
    public const TEXT_MINIMUM = 4.5;

    public const UI_MINIMUM = 3.0;

    public static function ratio(string $foreground, string $background): float
    {
        $lighter = max(self::luminance($foreground), self::luminance($background));
        $darker = min(self::luminance($foreground), self::luminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function luminance(string $hex): float
    {
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $channels) !== 1) {
            throw new InvalidArgumentException("Contrast needs an opaque #rrggbb colour, got [{$hex}].");
        }

        [$red, $green, $blue] = array_map(function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, array_slice($channels, 1));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }
}
