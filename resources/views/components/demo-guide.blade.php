@props([
    'step' => null,
    'hasIntake' => false,
    'intake' => null,
])

@php
    $ttl = max(1, (int) config('intake.demo.ttl_hours', 2));
    $activeStep = is_string($step) && $step !== '' ? $step : null;
    $pathChooseUrl = $intake ? route('demo.path.choose', $intake) : null;
@endphp

<div
    x-data="demoGuide({
        initialStep: @js($activeStep),
        hasIntake: @js((bool) $hasIntake),
        pathChooseUrl: @js($pathChooseUrl),
        csrf: @js(csrf_token()),
        createUrl: @js(route('intakes.create')),
        ttlHours: @js($ttl),
    })"
    x-cloak
    class="relative z-[60]"
>
    <div
        x-show="open"
        x-transition.opacity
        class="fixed inset-0 bg-[#1D1D1F]/45"
        @click="dismissible && close()"
    ></div>

    <div
        x-show="open"
        x-transition
        role="dialog"
        aria-modal="true"
        aria-labelledby="demo-guide-title"
        class="fixed inset-0 z-[61] flex items-end justify-center p-4 sm:items-center"
    >
        <div
            class="w-full max-w-lg overflow-hidden rounded-2xl border border-sky-200 bg-white shadow-2xl"
            @click.stop
            @keydown.escape.window="dismissible && close()"
        >
            <div class="border-b border-sky-100 bg-sky-50 px-5 py-3">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-700" x-text="metaLabel"></p>
            </div>
            <div class="space-y-3 px-5 py-5">
                <h3 id="demo-guide-title" class="text-xl font-semibold text-gray-950" x-text="title"></h3>
                <div class="space-y-2">
                    <template x-for="(line, index) in bodyLines" :key="index">
                        <p class="text-sm leading-relaxed text-gray-700" x-text="line"></p>
                    </template>
                </div>
                <p class="text-xs leading-relaxed text-gray-500" x-show="aside" x-text="aside"></p>
            </div>
            <div class="flex flex-col gap-2 border-t border-gray-100 bg-gray-50 px-5 py-4 sm:flex-row sm:justify-end">
                <template x-if="mode === 'branch'">
                    <div class="flex w-full flex-col gap-2 sm:flex-row sm:flex-row-reverse">
                        <form method="POST" :action="pathChooseUrl" class="flex-1">
                            <input type="hidden" name="_token" :value="csrf">
                            <input type="hidden" name="path" value="installer">
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-500">
                                Zelf de opname doen
                            </button>
                        </form>
                        <form method="POST" :action="pathChooseUrl" class="flex-1">
                            <input type="hidden" name="_token" :value="csrf">
                            <input type="hidden" name="path" value="customer">
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-900 hover:bg-gray-50">
                                Bekijk wat de klant ziet
                            </button>
                        </form>
                    </div>
                </template>
                <template x-if="mode !== 'branch'">
                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center justify-center rounded-xl bg-sky-700 px-5 text-sm font-semibold text-white hover:bg-sky-600"
                        @click="acknowledge()"
                        x-text="cta"
                    ></button>
                </template>
            </div>
        </div>
    </div>
</div>
