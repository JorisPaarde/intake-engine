@php
    $tabs = [
        'dev.dashboard' => 'Overzicht',
        'dev.intakes' => 'Opname-inspector',
        'dev.ai-runs' => 'AI-runs',
        'dev.activity' => 'Activiteit',
        'dev.health' => 'Systeem/health',
    ];
@endphp

<div class="border-b border-amber-300 bg-amber-50">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-2 sm:px-6 lg:px-8">
        <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-amber-800">
            <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>
            Dev-admin · {{ ucfirst(app()->environment()) }}
        </span>
        <nav class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
            @foreach ($tabs as $route => $label)
                <a
                    href="{{ route($route) }}"
                    @class([
                        'rounded px-2 py-1 font-medium transition',
                        'bg-amber-200 text-amber-900' => request()->routeIs($route) || request()->routeIs($route.'.*'),
                        'text-amber-800 hover:bg-amber-100' => ! (request()->routeIs($route) || request()->routeIs($route.'.*')),
                    ])
                >{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</div>
