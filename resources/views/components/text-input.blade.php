@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-11 rounded-xl border-[#dde2da] bg-white text-[#18201d] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)] disabled:bg-[#eef1ec] disabled:text-[#5e6862]']) }}>
