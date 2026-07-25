@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-11 rounded-xl border-[#D2D2D7] bg-white text-[#1D1D1F] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)] disabled:bg-[#F5F5F7] disabled:text-[#6E6E73]']) }}>
