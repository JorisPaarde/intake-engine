<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-11 items-center justify-center rounded-xl border border-transparent bg-[var(--tenant-primary)] px-4 py-2 text-sm font-semibold text-[var(--tenant-on-primary)] transition hover:bg-[var(--tenant-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
