<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex min-h-11 items-center justify-center rounded-xl border border-[#dde2da] bg-white px-4 py-2 text-sm font-semibold text-[#414b45] shadow-sm transition hover:bg-[#eef1ec] hover:text-[#18201d] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
