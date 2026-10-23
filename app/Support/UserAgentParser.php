<?php

namespace App\Support;

class UserAgentParser
{
    /**
     * Ordered so that browsers embedding another engine's token (Edge, Opera contain "Chrome") match first.
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /**
     * @var array<string, string>
     */
    private const PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    /**
     * @return array{browser: string, platform: string}
     */
    public static function parse(?string $userAgent): array
    {
        $userAgent ??= '';

        return [
            'browser' => self::firstMatch($userAgent, self::BROWSERS) ?? 'Unknown browser',
            'platform' => self::firstMatch($userAgent, self::PLATFORMS) ?? 'Unknown device',
        ];
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private static function firstMatch(string $userAgent, array $tokens): ?string
    {
        foreach ($tokens as $token => $label) {
            if (str_contains($userAgent, $token)) {
                return $label;
            }
        }

        return null;
    }
}
