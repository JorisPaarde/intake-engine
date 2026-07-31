@php
    $subjectRecords = $subject?->records ?? collect();
    $photoSuggestions = $subjectRecords
        ->filter(
            fn ($record) => $record->status === \App\Enums\DossierRecordStatus::Proposed
                && $record->source_type === 'ai'
                && $record->method === 'photo_inference'
                && is_string($record->value['text'] ?? null)
        )
        ->sortByDesc('id');
    $technicalNotes = $subjectRecords
        ->filter(
            fn ($record) => $record->status === \App\Enums\DossierRecordStatus::Established
                && $record->source_type === 'installer'
                && in_array($record->method, [
                    'installer_note',
                    'installer_confirmed',
                    'installer_adjusted',
                    'on_site',
                ], true)
                && is_string($record->value['text'] ?? null)
        )
        ->sortByDesc('id');
    $fieldPrefix = 'subject-'.$subject?->id;
@endphp

@if ($subject)
    @if ($photoSuggestions->isNotEmpty())
        <div class="mt-4 space-y-3">
            @foreach ($photoSuggestions as $suggestion)
                @php
                    $impactLabel = match ($suggestion->value['impact'] ?? null) {
                        'feasibility' => 'Kan de haalbaarheid beïnvloeden',
                        'materials' => 'Kan het materiaal beïnvloeden',
                        'cost' => 'Kan de prijs beïnvloeden',
                        'installation' => 'Kan de montage beïnvloeden',
                        default => 'Technische constatering',
                    };
                @endphp
                <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-3">
                    <p class="text-xs font-semibold text-indigo-700">Op foto herkend · nog bevestigen</p>
                    <p class="mt-1 text-sm font-medium leading-relaxed text-gray-950">{{ $suggestion->value['text'] }}</p>
                    <p class="mt-1 text-xs text-gray-600">{{ $impactLabel }}</p>
                    <div class="mt-3 flex flex-wrap items-start gap-2">
                        <form method="POST" action="{{ route('intakes.workspace.photo-observations.confirm', [$intake, $suggestion]) }}">
                            @csrf
                            <button class="inline-flex min-h-10 items-center rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                                Klopt
                            </button>
                        </form>
                        <details class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                            <summary class="inline-flex min-h-10 cursor-pointer items-center rounded-lg border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-800">
                                Aanpassen
                            </summary>
                            <form method="POST" action="{{ route('intakes.workspace.photo-observations.confirm', [$intake, $suggestion]) }}" class="mt-2 space-y-2">
                                @csrf
                                <label for="{{ $fieldPrefix }}-suggestion-{{ $suggestion->id }}" class="block text-xs font-semibold text-gray-700">
                                    Wat is technisch vastgesteld?
                                </label>
                                <textarea
                                    id="{{ $fieldPrefix }}-suggestion-{{ $suggestion->id }}"
                                    name="text"
                                    rows="3"
                                    class="block w-full rounded-xl border-gray-300 text-sm"
                                    required
                                >{{ $suggestion->value['text'] }}</textarea>
                                <button class="inline-flex min-h-10 items-center rounded-lg bg-gray-950 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-800">
                                    Aanpassing bevestigen
                                </button>
                            </form>
                        </details>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($technicalNotes->isNotEmpty())
        <div class="mt-4 space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Technische notities</p>
            @foreach ($technicalNotes as $note)
                <div class="rounded-xl bg-gray-50 px-3 py-2">
                    <p class="text-sm leading-relaxed text-gray-800">{{ $note->value['text'] }}</p>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ match ($note->method) {
                            'installer_confirmed' => 'Door installateur bevestigd',
                            'installer_adjusted' => 'Door installateur aangepast en bevestigd',
                            'on_site' => 'Ter plaatse vastgesteld',
                            default => 'Door installateur toegevoegd',
                        } }}
                    </p>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        <details class="min-w-0 basis-full rounded-xl border border-gray-200 bg-gray-50">
            <summary class="flex min-h-11 cursor-pointer list-none items-center px-3 py-2 text-sm font-semibold text-gray-800">
                Foto maken
            </summary>
            <form
                method="POST"
                enctype="multipart/form-data"
                action="{{ route('intakes.workspace.photos.store', [$intake, $subject]) }}"
                class="space-y-3 border-t border-gray-200 p-3"
            >
                @csrf
                <div>
                    <label
                        for="{{ $fieldPrefix }}-photo"
                        class="flex min-h-24 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white px-3 text-center"
                    >
                        <span class="text-sm font-semibold text-gray-900">Camera openen of foto kiezen</span>
                        <span class="mt-1 text-xs text-gray-500">JPEG, PNG, WebP of HEIC</span>
                    </label>
                    <input
                        id="{{ $fieldPrefix }}-photo"
                        type="file"
                        name="photo"
                        accept="image/*,.heic,.heif"
                        class="sr-only"
                        required
                    >
                </div>
                @if ($connection)
                    <div>
                        <label for="{{ $fieldPrefix }}-segment-label" class="block text-xs font-semibold text-gray-700">
                            Wat laat deze foto zien? <span class="font-normal text-gray-500">(optioneel)</span>
                        </label>
                        <input
                            id="{{ $fieldPrefix }}-segment-label"
                            name="route_segment_label"
                            class="mt-1 block min-h-11 w-full rounded-xl border-gray-300 text-sm"
                            placeholder="Bijv. andere kant van de wand"
                        >
                    </div>
                @endif
                <button class="inline-flex min-h-10 items-center rounded-lg bg-gray-950 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-800">
                    Foto opslaan
                </button>
            </form>
        </details>

        <details class="min-w-0 basis-full rounded-xl border border-gray-200 bg-gray-50">
            <summary class="flex min-h-11 cursor-pointer list-none items-center px-3 py-2 text-sm font-semibold text-gray-800">
                Technische notitie toevoegen
            </summary>
            <form
                method="POST"
                action="{{ route('intakes.workspace.notes.store', [$intake, $subject]) }}"
                class="space-y-3 border-t border-gray-200 p-3"
            >
                @csrf
                <div>
                    <label for="{{ $fieldPrefix }}-note" class="block text-xs font-semibold text-gray-700">
                        Wat is hier technisch van belang?
                    </label>
                    <textarea
                        id="{{ $fieldPrefix }}-note"
                        name="text"
                        rows="3"
                        class="mt-1 block w-full rounded-xl border-gray-300 text-sm"
                        placeholder="Bijv. Massieve buitenmuur, vanaf de grond bereikbaar."
                        required
                    ></textarea>
                </div>
                <button class="inline-flex min-h-10 items-center rounded-lg bg-gray-950 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-800">
                    Notitie toevoegen
                </button>
            </form>
        </details>
    </div>
@endif
