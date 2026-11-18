@props(['appearance'])

{{--
    The colour tokens for this page, in the head so the first paint is already in the right theme. "System"
    carries both modes and lets the browser choose with prefers-color-scheme; no script is involved.
--}}
<meta name="color-scheme" content="{{ $appearance['theme'] === 'system' ? 'light dark' : $appearance['theme'] }}">
<style id="theme-tokens">{!! app(\App\Support\Appearance\Palette::class)->rootStylesheet($appearance['profile'], $appearance['theme']) !!}</style>
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
