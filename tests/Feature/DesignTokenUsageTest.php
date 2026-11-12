<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DesignTokenUsageTest extends TestCase
{
    /**
     * Files that keep literal colours on purpose. Everything else must use design tokens.
     *
     * @var array<string, string> path relative to the project root => reason
     */
    private const EXEMPT = [
        'resources/views/invoices/document.blade.php' => 'An invoice is printed paper and dompdf has no CSS custom properties.',
        'resources/views/emails/user-invitation.blade.php' => 'Email clients strip CSS custom properties.',
    ];

    public function test_views_and_components_use_only_design_tokens_for_colour(): void
    {
        $paletteClass = '/(?<![\w-])(?:[a-z-]+:)*(?:bg|text|border|ring|ring-offset|divide|from|to|via|fill|stroke|outline|placeholder|decoration|shadow|accent|caret)-'
            .'(?:white|black|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|navy)(?:-\d{2,3})?(?:\/[\d.]+)?(?![\w-])/';
        $literalColour = '/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b(?![\w-])|\brgba?\(|\bhsla?\(/';
        $violations = [];

        foreach ([...File::allFiles(resource_path('views')), ...File::allFiles(app_path())] as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());
            $path = str_starts_with($file->getPathname(), app_path()) ? "app/{$path}" : "resources/views/{$path}";

            if (array_key_exists($path, self::EXEMPT)) {
                continue;
            }

            foreach (preg_split('/\R/', $file->getContents()) as $number => $line) {
                // Blade comments and HTML entities like &#39; are not colours.
                $line = preg_replace(['/\{\{--.*?--\}\}/', '/&#\w+;/'], '', $line);

                if (preg_match($paletteClass, $line, $match) || preg_match($literalColour, $line, $match)) {
                    $violations[] = "{$path}:".($number + 1).": {$match[0]}";
                }
            }
        }

        $this->assertSame([], $violations, 'Use a design token (config/appearance.php) instead of a literal colour.');
    }

    public function test_the_exempt_files_still_exist(): void
    {
        foreach (array_keys(self::EXEMPT) as $path) {
            $this->assertFileExists(base_path($path));
        }
    }
}
