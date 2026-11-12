<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Error {{ $status }} | AquaOps</title>
    <style>
        /*
         * Error pages stand alone (no asset build, no database), so they always use the default profile's light
         * tokens from config/appearance.php rather than the visitor's theme.
         */
        :root { {!! app(\App\Support\Appearance\Palette::class)->declarations(config('appearance.default_profile'), 'light') !!} --radius: 8px; }

        * { box-sizing: border-box; }
        body {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
            margin: 0;
            background: var(--c-background);
            color: var(--c-text);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .topbar {
            height: 52px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 24px;
            background: var(--c-sidebar);
            color: var(--c-sidebar-strong);
        }
        .brand-mark {
            display: flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            background: linear-gradient(135deg, var(--c-brand-from), var(--c-brand-to));
            color: var(--c-on-brand);
        }
        .brand-mark svg { width: 16px; height: 16px; }
        .brand-name { font-size: 14px; font-weight: 800; }
        .brand-caption {
            margin-left: auto;
            color: var(--c-muted);
            font-size: 11px;
            font-weight: 500;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .error-stage {
            position: relative;
            display: flex;
            flex: 1;
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
            background: var(--c-surface);
        }
        .error-stage.has-pattern {
            background-color: var(--c-surface);
            background-image: radial-gradient(var(--c-border) 1px, transparent 1px);
            background-size: 22px 22px;
        }
        .error-card {
            position: relative;
            z-index: 1;
            width: min(100%, 440px);
            text-align: center;
        }
        .error-badge {
            display: flex;
            width: 64px;
            height: 64px;
            align-items: center;
            justify-content: center;
            margin: 0 auto 22px;
            border-radius: 16px;
        }
        .error-badge svg { width: 30px; height: 30px; }
        .btn svg { width: 16px; height: 16px; flex-shrink: 0; }
        .badge-amber { background: var(--c-warning-soft); color: var(--c-warning-ink); }
        .badge-blue { background: var(--c-accent-soft); color: var(--c-accent-ink); }
        .badge-red { background: var(--c-danger-soft); color: var(--c-danger-ink); }
        .error-code {
            margin-bottom: 10px;
            color: var(--c-muted);
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .error-title {
            margin: 0 0 10px;
            color: var(--c-text);
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -.2px;
        }
        .error-desc {
            margin: 0 0 22px;
            color: var(--c-muted);
            font-size: 13.5px;
            line-height: 1.6;
        }
        .module-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 24px;
            padding: 6px 14px;
            border: 1px solid var(--c-border);
            border-radius: 20px;
            background: var(--c-surface-strong);
            color: var(--c-text-soft);
            font-size: 12px;
            font-weight: 600;
        }
        .error-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }
        .btn {
            display: inline-flex;
            min-height: 40px;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 10px 18px;
            border: 1px solid transparent;
            border-radius: 7px;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-primary { background: var(--c-accent); color: var(--c-on-accent); }
        .btn-secondary {
            border-color: var(--c-border-strong);
            background: var(--c-surface);
            color: var(--c-text-soft);
        }
        .btn:hover { filter: brightness(.96); }
        .error-meta {
            display: flex;
            justify-content: center;
            gap: 22px;
            margin-top: 28px;
            padding-top: 18px;
            border-top: 1px solid var(--c-surface-strong);
            color: var(--c-muted);
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 11px;
        }
        .site-footer {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 6px 16px;
            padding: 12px 24px;
            border-top: 1px solid var(--c-border);
            background: var(--c-background);
            color: var(--c-muted);
            font-size: 12px;
        }
        .site-footer strong { color: var(--c-text-soft); font-weight: 600; }
        @media (max-width: 480px) {
            .brand-caption { display: none; }
            .error-stage { padding: 32px 18px; }
            .error-meta { flex-direction: column; gap: 6px; }
            .site-footer { flex-direction: column; padding: 12px 18px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <span class="brand-mark" aria-hidden="true"><x-brand-droplet /></span>
        <span class="brand-name">AquaOps</span>
        <span class="brand-caption">Operations Console</span>
    </header>

    <main @class(['error-stage', 'has-pattern' => $pattern ?? false])>
        <section class="error-card" aria-labelledby="error-title">
            <div class="error-badge badge-{{ $tone }}" aria-hidden="true"><x-dynamic-component :component="'heroicon-o-'.$icon" /></div>
            <div class="error-code">Error {{ $status }}</div>
            <h1 id="error-title" class="error-title">{{ $title }}</h1>
            <p class="error-desc">{{ $description }}</p>

            @isset($chip)
                <div class="module-chip">{{ $chip }}</div>
            @endisset

            <div class="error-actions">
                @foreach ($actions as $action)
                    @php
                        $actionIcon = isset($action['icon']) ? 'heroicon-o-'.$action['icon'] : null;
                    @endphp
                    @if ($action['reload'] ?? false)
                        <button type="button" class="btn btn-{{ $action['style'] }}" onclick="window.location.reload()">
                            @if ($actionIcon)<x-dynamic-component :component="$actionIcon" aria-hidden="true" />@endif
                            {{ $action['label'] }}
                        </button>
                    @elseif ($action['history'] ?? false)
                        <button type="button" class="btn btn-{{ $action['style'] }}" onclick="window.history.back()">
                            @if ($actionIcon)<x-dynamic-component :component="$actionIcon" aria-hidden="true" />@endif
                            {{ $action['label'] }}
                        </button>
                    @elseif (isset($action['form']))
                        <form method="POST" action="{{ $action['form'] }}">
                            @csrf
                            @foreach ($action['fields'] ?? [] as $fieldName => $fieldValue)
                                <input type="hidden" name="{{ $fieldName }}" value="{{ $fieldValue }}">
                            @endforeach
                            <button type="submit" class="btn btn-{{ $action['style'] }}">
                                @if ($actionIcon)<x-dynamic-component :component="$actionIcon" aria-hidden="true" />@endif
                                {{ $action['label'] }}
                            </button>
                        </form>
                    @else
                        <a class="btn btn-{{ $action['style'] }}" href="{{ $action['href'] }}">
                            @if ($actionIcon)<x-dynamic-component :component="$actionIcon" aria-hidden="true" />@endif
                            {{ $action['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>

            @if ($showMeta ?? false)
                <footer class="error-meta">
                    <span>REF: {{ $reference ?? 'UNAVAILABLE' }}</span>
                </footer>
            @endif
        </section>
    </main>

    @php($footerDetails = app(\App\Support\FooterDetails::class))
    <footer class="site-footer">
        <span><strong>{{ $footerDetails->organisationName() }}</strong> &middot; &copy; {{ $footerDetails->year() }}</span>
        <span>AquaOps v{{ $footerDetails->version() }}</span>
    </footer>
</body>
</html>
