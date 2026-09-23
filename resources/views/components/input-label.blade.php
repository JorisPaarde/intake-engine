@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-[#414b45]']) }}>
    {{ $value ?? $slot }}
</label>
