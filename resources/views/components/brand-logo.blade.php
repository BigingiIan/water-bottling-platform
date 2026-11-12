@props(['size' => 'size-10', 'alt' => ''])

@php
    $logoUrl = app(\App\Support\Branding::class)->logoUrl();
@endphp

{{-- The organisation's uploaded logo, or the AquaOps mark when none is set. --}}
@if ($logoUrl)
    <img src="{{ $logoUrl }}" alt="{{ $alt }}" {{ $attributes->class(['shrink-0 rounded-md object-contain', $size]) }} decoding="async">
@else
    <x-brand-mark :size="$size" />
@endif
