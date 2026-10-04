@php
    if (! isset($reference)) {
        $rawReference = strtoupper(bin2hex(random_bytes(4)));
        $reference = substr($rawReference, 0, 4).'-'.substr($rawReference, 4);
    }
    $renderedAt = now();
    $timestamp = $renderedAt->format('d M Y, H:i');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Error {{ $status }} | {{ config('app.name', 'AquaOps') }}</title>
    @fonts
    <style>
        :root {
            --navy-950: #0b1220;
            --navy-900: #0f1b2e;
            --navy-700: #1e3350;
            --blue-600: #2563eb;
            --blue-500: #3b82f6;
            --blue-50: #eff6ff;
            --teal-500: #14b8a6;
            --amber-500: #d97706;
            --amber-50: #fffbeb;
            --red-500: #dc2626;
            --red-50: #fef2f2;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --radius: 8px;
        }

        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            background: var(--gray-50);
            color: var(--gray-800);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .topbar {
            height: 52px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 24px;
            background: var(--navy-950);
            color: white;
        }
        .brand-mark {
            display: flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            background: linear-gradient(135deg, var(--blue-500), var(--teal-500));
            font-size: 15px;
        }
        .brand-name { font-size: 14px; font-weight: 800; }
        .brand-caption {
            margin-left: auto;
            color: var(--gray-400);
            font-size: 11px;
            font-weight: 500;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .error-stage {
            position: relative;
            display: flex;
            min-height: calc(100vh - 52px);
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
            background:
                radial-gradient(circle at 20% 20%, rgba(59,130,246,.04), transparent 40%),
                radial-gradient(circle at 85% 75%, rgba(20,184,166,.04), transparent 40%),
                #fff;
        }
        .error-stage.has-pattern {
            background-color: #fff;
            background-image: radial-gradient(var(--gray-200) 1px, transparent 1px);
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
            font-size: 28px;
        }
        .badge-amber { background: linear-gradient(135deg, #fef3c7, #fde68a); }
        .badge-blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
        .badge-red { background: linear-gradient(135deg, #fee2e2, #fecaca); }
        .error-code {
            margin-bottom: 10px;
            color: var(--gray-400);
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .error-title {
            margin: 0 0 10px;
            color: var(--gray-900);
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -.2px;
        }
        .error-desc {
            margin: 0 0 22px;
            color: var(--gray-500);
            font-size: 13.5px;
            line-height: 1.6;
        }
        .module-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 24px;
            padding: 6px 14px;
            border: 1px solid var(--gray-200);
            border-radius: 20px;
            background: var(--gray-100);
            color: var(--gray-600);
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
        .btn-primary { background: var(--blue-600); color: #fff; }
        .btn-secondary {
            border-color: var(--gray-300);
            background: #fff;
            color: var(--gray-700);
        }
        .btn:hover { filter: brightness(.96); }
        .error-meta {
            display: flex;
            justify-content: center;
            gap: 22px;
            margin-top: 28px;
            padding-top: 18px;
            border-top: 1px solid var(--gray-100);
            color: var(--gray-400);
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 11px;
        }
        @media (max-width: 480px) {
            .brand-caption { display: none; }
            .error-stage { min-height: calc(100vh - 52px); padding: 32px 18px; }
            .error-meta { flex-direction: column; gap: 6px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <span class="brand-mark" aria-hidden="true">💧</span>
        <span class="brand-name">AquaOps</span>
        <span class="brand-caption">Operations Console</span>
    </header>

    <main @class(['error-stage', 'has-pattern' => $pattern ?? false])>
        <section class="error-card" aria-labelledby="error-title">
            <div class="error-badge badge-{{ $tone }}" aria-hidden="true">{{ $icon }}</div>
            <div class="error-code">Error {{ $status }}</div>
            <h1 id="error-title" class="error-title">{{ $title }}</h1>
            <p class="error-desc">{{ $description }}</p>

            @isset($chip)
                <div class="module-chip">{{ $chip }}</div>
            @endisset

            <div class="error-actions">
                @foreach ($actions as $action)
                    @if ($action['reload'] ?? false)
                        <button type="button" class="btn btn-{{ $action['style'] }}" onclick="window.location.reload()">
                            {{ $action['label'] }}
                        </button>
                    @elseif ($action['history'] ?? false)
                        <button type="button" class="btn btn-{{ $action['style'] }}" onclick="window.history.back()">
                            {{ $action['label'] }}
                        </button>
                    @else
                        <a class="btn btn-{{ $action['style'] }}" href="{{ $action['href'] }}">
                            {{ $action['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>

            @if ($showMeta ?? false)
                <footer class="error-meta">
                    <span>REF: {{ $reference }}</span>
                    <time datetime="{{ $renderedAt->toIso8601String() }}">{{ $timestamp }}</time>
                </footer>
            @endif
        </section>
    </main>
</body>
</html>
