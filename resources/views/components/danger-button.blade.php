<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-11 items-center justify-center rounded-xl border border-transparent bg-[#a84832] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#912018] focus:outline-none focus:ring-2 focus:ring-[#a84832] focus:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
