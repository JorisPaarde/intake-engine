@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block min-h-11 w-full border-l-4 border-[var(--tenant-primary)] bg-[#F5F5F7] py-2 pe-4 ps-3 text-start text-base font-medium text-[#1D1D1F] transition focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2'
            : 'block min-h-11 w-full border-l-4 border-transparent py-2 pe-4 ps-3 text-start text-base font-medium text-[#6E6E73] transition hover:border-[#D2D2D7] hover:bg-[#F5F5F7] hover:text-[#1D1D1F] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
