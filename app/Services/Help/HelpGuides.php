<?php

namespace App\Services\Help;

use App\Models\User;
use App\Support\Dashboard\DashboardWidgetRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Loads the Markdown guides in resources/help and decides which ones a user may read.
 *
 * A guide with a `module` in its front matter is shown only to roles with view or full access to that
 * module; guides without one (Getting started, Asking another department) are shown to everyone.
 */
class HelpGuides
{
    private const SEARCH_RESULT_LIMIT = 20;

    /**
     * @var array<string, array{slug: string, title: string, module: ?string, order: int, summary: string, sections: list<array{id: string, level: int, title: string, html: string, text: string}>}>|null
     */
    private ?array $guides = null;

    public function __construct(private DashboardWidgetRegistry $registry) {}

    /**
     * @return array<string, array{slug: string, title: string, module: ?string, order: int, summary: string, sections: list<array{id: string, level: int, title: string, html: string, text: string}>}>
     */
    public function all(): array
    {
        if ($this->guides !== null) {
            return $this->guides;
        }

        $guides = [];

        foreach (File::glob(resource_path('help/*.md')) as $path) {
            $guide = $this->parse(pathinfo($path, PATHINFO_FILENAME), (string) File::get($path));
            $guides[$guide['slug']] = $guide;
        }

        uasort($guides, fn (array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);

        return $this->guides = $guides;
    }

    /**
     * @return array<string, array{slug: string, title: string, module: ?string, order: int, summary: string, sections: list<array{id: string, level: int, title: string, html: string, text: string}>}>
     */
    public function visibleTo(User $user): array
    {
        return array_filter($this->all(), fn (array $guide): bool => $this->canRead($user, $guide['slug']));
    }

    public function canRead(User $user, string $slug): bool
    {
        $guide = $this->all()[$slug] ?? null;

        return $guide !== null && ($guide['module'] === null || $this->registry->canAccessModule($user, $guide['module']));
    }

    /**
     * Sections of the user's visible guides whose title and text contain every word of the query.
     *
     * @return list<array{guide: string, guideTitle: string, section: string, sectionTitle: string, snippet: string}>
     */
    public function search(User $user, string $query): array
    {
        $terms = array_values(array_filter(
            preg_split('/\s+/', Str::lower(trim($query))) ?: [],
            fn (string $term): bool => mb_strlen($term) >= 2,
        ));

        if ($terms === []) {
            return [];
        }

        $results = [];

        foreach ($this->visibleTo($user) as $guide) {
            foreach ($guide['sections'] as $section) {
                $haystack = Str::lower($section['title'].' '.$section['text']);

                if (collect($terms)->every(fn (string $term): bool => str_contains($haystack, $term))) {
                    $results[] = [
                        'guide' => $guide['slug'],
                        'guideTitle' => $guide['title'],
                        'section' => $section['id'],
                        'sectionTitle' => $section['title'],
                        'snippet' => $this->snippet($section['text'], $terms[0]),
                    ];
                }
            }
        }

        return array_slice($results, 0, self::SEARCH_RESULT_LIMIT);
    }

    public function sectionId(string $guideSlug, string $heading): string
    {
        return $guideSlug.'-'.Str::slug($heading);
    }

    /**
     * @return array{slug: string, title: string, module: ?string, order: int, summary: string, sections: list<array{id: string, level: int, title: string, html: string, text: string}>}
     */
    private function parse(string $slug, string $contents): array
    {
        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $contents, $matches) !== 1) {
            throw new InvalidArgumentException("Help guide [{$slug}] needs a front matter block.");
        }

        $meta = [];

        foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = array_map('trim', explode(':', $line, 2));
                $meta[$key] = $value;
            }
        }

        return [
            'slug' => $slug,
            'title' => $meta['title'] ?? Str::headline($slug),
            'module' => ($meta['module'] ?? '') !== '' ? $meta['module'] : null,
            'order' => (int) ($meta['order'] ?? 100),
            'summary' => $meta['summary'] ?? '',
            'sections' => $this->sections($slug, $matches[2]),
        ];
    }

    /**
     * Splits the body at `##` and `###` headings so each heading becomes a linkable, searchable section.
     *
     * @return list<array{id: string, level: int, title: string, html: string, text: string}>
     */
    private function sections(string $slug, string $body): array
    {
        $sections = [];
        $current = null;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^(#{2,3})\s+(.+)$/', $line, $heading) === 1) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                $current = ['level' => strlen($heading[1]), 'title' => trim($heading[2]), 'markdown' => ''];

                continue;
            }

            if ($current !== null) {
                $current['markdown'] .= $line."\n";
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        return array_map(function (array $section) use ($slug): array {
            $html = trim(Str::markdown($section['markdown'], [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]));

            return [
                'id' => $this->sectionId($slug, $section['title']),
                'level' => $section['level'],
                'title' => $section['title'],
                'html' => $html,
                'text' => trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''),
            ];
        }, $sections);
    }

    private function snippet(string $text, string $term): string
    {
        $position = mb_stripos($text, $term);
        $start = $position === false ? 0 : max(0, $position - 50);
        $snippet = mb_substr($text, $start, 150);

        return ($start > 0 ? '…' : '').$snippet.(mb_strlen($text) > $start + 150 ? '…' : '');
    }
}
