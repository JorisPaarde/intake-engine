@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-[#424245]']) }}>
    {{ $value ?? $slot }}
</label>
