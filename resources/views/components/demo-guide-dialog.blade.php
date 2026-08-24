@props([
    'step' => 'customer_start',
    'returnUrl' => null,
])

{{-- BL-070: no extra customer tour overlays; a short status banner covers context. --}}
@php
    // Intentionally empty: customer path uses demo-scope-notice instead of stacked dialogs.
@endphp
