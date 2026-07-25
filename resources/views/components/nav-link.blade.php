@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex min-h-16 items-center border-b-2 border-[var(--tenant-primary)] px-1 pt-1 text-sm font-medium leading-5 text-[#1D1D1F] transition focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2'
            : 'inline-flex min-h-16 items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium leading-5 text-[#6E6E73] transition hover:border-[#D2D2D7] hover:text-[#1D1D1F] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
