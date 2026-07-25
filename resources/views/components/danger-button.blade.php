<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-11 items-center justify-center rounded-xl border border-transparent bg-[#B42318] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#912018] focus:outline-none focus:ring-2 focus:ring-[#B42318] focus:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
