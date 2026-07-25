<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex min-h-11 items-center justify-center rounded-xl border border-[#D2D2D7] bg-white px-4 py-2 text-sm font-semibold text-[#424245] shadow-sm transition hover:bg-[#F5F5F7] hover:text-[#1D1D1F] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
