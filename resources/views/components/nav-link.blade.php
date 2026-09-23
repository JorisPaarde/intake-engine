@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex min-h-16 items-center border-b-2 border-[var(--tenant-primary)] px-1 pt-1 text-sm font-medium leading-5 text-[#18201d] transition focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2'
            : 'inline-flex min-h-16 items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium leading-5 text-[#5e6862] transition hover:border-[#dde2da] hover:text-[#18201d] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
