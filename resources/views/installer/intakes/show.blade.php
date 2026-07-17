<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $intake->customer_name }}
            </h2>
            <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">← Terug naar overzicht</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                        {{ $intake->status->label() }}
                    </span>
                    <span class="text-sm text-gray-500">{{ $intake->progress_percent }}% compleet</span>
                    <span class="text-sm text-gray-500">{{ $intake->templateVersion?->template?->name }} · v{{ $intake->templateVersion?->version }}</span>
                </div>

                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 text-sm">
                    <div>
                        <dt class="text-gray-500">E-mail</dt>
                        <dd class="text-gray-900">{{ $intake->customer_email }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Telefoon</dt>
                        <dd class="text-gray-900">{{ $intake->customer_phone ?: '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Adres</dt>
                        <dd class="text-gray-900">{{ $intake->fullAddress() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Aangemaakt</dt>
                        <dd class="text-gray-900">{{ $intake->created_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Afgerond</dt>
                        <dd class="text-gray-900">{{ $intake->completed_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') ?? '—' }}</dd>
                    </div>
                    @if ($intake->internal_note)
                        <div class="sm:col-span-2">
                            <dt class="text-gray-500">Interne notitie</dt>
                            <dd class="text-gray-900 whitespace-pre-wrap">{{ $intake->internal_note }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4" x-data="{ copied: false }">
                <h3 class="text-base font-semibold text-gray-900">Klantlink</h3>
                <p class="text-sm text-gray-600">
                    Deel deze link met de klant. Automatische e-mail volgt later; kopieer de link voor nu handmatig.
                    @if ($intake->token_expires_at)
                        Geldig tot {{ $intake->token_expires_at->timezone(config('app.timezone'))->format('d-m-Y') }}.
                    @endif
                    @if ($intake->token_revoked_at)
                        <span class="text-red-600 font-medium">Ingetrokken op {{ $intake->token_revoked_at->timezone(config('app.timezone'))->format('d-m-Y H:i') }}.</span>
                    @endif
                </p>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <input
                        id="customer-link"
                        type="text"
                        readonly
                        value="{{ $intake->customerUrl() }}"
                        class="block w-full rounded-md border-gray-300 bg-gray-50 text-sm shadow-sm"
                    >
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700"
                        @click="
                            navigator.clipboard.writeText(document.getElementById('customer-link').value);
                            copied = true;
                            setTimeout(() => copied = false, 2000);
                        "
                    >
                        <span x-show="!copied">Kopiëren</span>
                        <span x-cloak x-show="copied">Gekopieerd</span>
                    </button>
                </div>

                <div class="flex flex-wrap gap-3 pt-2">
                    @if ($intake->token_revoked_at === null && $intake->status !== \App\Enums\IntakeStatus::Cancelled)
                        <form method="POST" action="{{ route('intakes.revoke', $intake) }}" onsubmit="return confirm('Klantlink intrekken en opname annuleren?')">
                            @csrf
                            <x-danger-button>Link intrekken</x-danger-button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('intakes.regenerate-token', $intake) }}" onsubmit="return confirm('Nieuwe link genereren? De oude link werkt daarna niet meer.')">
                        @csrf
                        <x-secondary-button>Nieuwe link genereren</x-secondary-button>
                    </form>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-base font-semibold text-gray-900">Foto’s</h3>
                @if ($intake->uploads->isEmpty())
                    <p class="text-sm text-gray-500">Nog geen foto’s geüpload.</p>
                @else
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        @foreach ($intake->uploads->sortBy('sort_order') as $upload)
                            <figure class="space-y-2">
                                <a href="{{ route('installer.uploads.show', [$intake, $upload]) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-md border border-gray-200">
                                    <img
                                        src="{{ route('installer.uploads.show', [$intake, $upload]) }}"
                                        alt="{{ $upload->original_filename }}"
                                        class="aspect-square w-full object-cover"
                                    >
                                </a>
                                <figcaption class="text-xs text-gray-500">
                                    {{ $upload->question_key }}
                                    @if ($upload->section_instance_key)
                                        · {{ $upload->section_instance_key }}
                                    @endif
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
