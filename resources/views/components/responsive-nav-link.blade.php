@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block min-h-11 w-full border-l-4 border-[var(--tenant-primary)] bg-[#eef1ec] py-2 pe-4 ps-3 text-start text-base font-medium text-[#18201d] transition focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2'
            : 'block min-h-11 w-full border-l-4 border-transparent py-2 pe-4 ps-3 text-start text-base font-medium text-[#5e6862] transition hover:border-[#dde2da] hover:bg-[#eef1ec] hover:text-[#18201d] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
