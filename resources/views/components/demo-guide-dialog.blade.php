@props([
    'step' => 'customer_start',
    'returnUrl' => null,
])

@php
    $ttl = max(1, (int) config('intake.demo.ttl_hours', 2));
    $copy = match ($step) {
        'customer_done' => [
            'meta' => 'Stap 6 van 6 · Terug naar opname',
            'title' => 'Klantgedeelte afgerond',
            'body' => 'Normaal krijgt de installateur een mail. Hier open je de opname meteen om verder te kijken.',
            'aside' => null,
            'cta' => 'Naar de opname',
        ],
        default => [
            'meta' => 'Stap 4 van 6 · Klantweergave',
            'title' => 'Dit ziet je klant',
            'body' => 'De klant krijgt simpele stappen, geen technische keuzes. Foto’s kan de app meekijken. Deze demo is korter dan echt.',
            'aside' => 'Na afronden ga je terug naar de opname. De demo verdwijnt na '.$ttl.' uur.',
            'cta' => 'Begin als klant',
        ],
    };
    $storageKey = 'intake-demo-guide-dismissed';
@endphp

<dialog
    id="demo-guide-dialog-{{ $step }}"
    class="w-full max-w-lg rounded-2xl border border-sky-200 bg-white p-0 shadow-2xl backdrop:bg-[#1D1D1F]/45"
    data-demo-step="{{ $step }}"
>
    <div class="border-b border-sky-100 bg-sky-50 px-5 py-3">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-700">{{ $copy['meta'] }}</p>
    </div>
    <div class="space-y-3 px-5 py-5">
        <h3 class="text-xl font-semibold text-gray-950">{{ $copy['title'] }}</h3>
        <p class="text-sm leading-relaxed text-gray-700">{{ $copy['body'] }}</p>
        @if ($copy['aside'])
            <p class="text-xs leading-relaxed text-gray-500">{{ $copy['aside'] }}</p>
        @endif
    </div>
    <div class="flex justify-end border-t border-gray-100 bg-gray-50 px-5 py-4">
        @if ($step === 'customer_done' && $returnUrl)
            <a
                href="{{ $returnUrl }}"
                class="inline-flex min-h-11 items-center justify-center rounded-xl bg-sky-700 px-5 text-sm font-semibold text-white hover:bg-sky-600"
                onclick="try{const k=@js($storageKey);const list=JSON.parse(sessionStorage.getItem(k)||'[]');if(!list.includes(@js($step))){list.push(@js($step));sessionStorage.setItem(k,JSON.stringify(list));}}catch(e){}"
            >
                {{ $copy['cta'] }}
            </a>
        @else
            <button
                type="button"
                class="inline-flex min-h-11 items-center justify-center rounded-xl bg-sky-700 px-5 text-sm font-semibold text-white hover:bg-sky-600"
                onclick="(function(btn){const d=btn.closest('dialog');try{const k=@js($storageKey);const list=JSON.parse(sessionStorage.getItem(k)||'[]');if(!list.includes(@js($step))){list.push(@js($step));sessionStorage.setItem(k,JSON.stringify(list));}}catch(e){}d.close();})(this)"
            >
                {{ $copy['cta'] }}
            </button>
        @endif
    </div>
</dialog>

<script>
    (function () {
        const step = @js($step);
        const key = @js($storageKey);
        try {
            const list = JSON.parse(sessionStorage.getItem(key) || '[]');
            if (Array.isArray(list) && list.includes(step)) {
                return;
            }
        } catch (e) {}
        const dialog = document.getElementById(@js('demo-guide-dialog-'.$step));
        if (dialog && typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
    })();
</script>
