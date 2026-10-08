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
    $photoSuggestions = app(\App\Domains\Intake\Support\PhotoObservationRelevance::class)
        ->filterForDisplay($intake, $photoSuggestions);
    $photoObservationRelevance = app(\App\Domains\Intake\Support\PhotoObservationRelevance::class);
    $assumptions = $subjectRecords
        ->filter(
            fn ($record) => $record->status === \App\Enums\DossierRecordStatus::Proposed
                && $record->superseded_by_id === null
                && in_array($record->method, ['ai_assumption', 'ai_proposal', 'targeted_customer_task'], true)
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
                    'installer_corrected',
                    'on_site',
                ], true)
                && is_string($record->value['text'] ?? $record->value['_display_value'] ?? null)
        )
        ->sortByDesc('id');
    $fieldPrefix = 'subject-'.$subject?->id;
    $subjectPhotos = $subject
        ? $intake->uploads
            ->filter(
                static fn ($upload): bool => $upload->section_instance_key === 'subject-'.$subject->id
            )
            ->sortByDesc('id')
            ->take(4)
            ->values()
        : collect();
    $subjectContributions = $subject
        ? app(\App\Domains\Intake\Support\FollowUpContributionPresenter::class)
            ->forSubject($intake, (int) $subject->id)
        : [];
@endphp

@if ($subject)
    @if ($subjectContributions !== [])
        <div class="mt-4 space-y-2" data-testid="subject-contribution-evidence">
            <p class="text-xs font-semibold uppercase tracking-[0.06em] text-emerald-800">Nieuwe aanvulling bij dit onderdeel</p>
            @foreach ($subjectContributions as $contribution)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2" data-testid="contribution-item">
                    @if (is_string($contribution['response_text'] ?? null) && trim((string) $contribution['response_text']) !== '')
                        <p class="text-sm font-medium text-gray-950" data-testid="contribution-response">{{ $contribution['response_text'] }}</p>
                    @endif
                    @if (($contribution['uploads'] ?? []) !== [])
                        <ul class="mt-2 grid grid-cols-4 gap-2">
                            @foreach ($contribution['uploads'] as $contributionUpload)
                                <li>
                                    <a href="{{ route('installer.uploads.show', [$intake, $contributionUpload]) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-emerald-200 bg-white">
                                        <img src="{{ route('installer.uploads.show', [$intake, $contributionUpload]) }}" alt="Aanvullende foto" class="aspect-square w-full object-cover">
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if (($contribution['ai_facts'] ?? []) !== [])
                        <ul class="mt-2 space-y-0.5">
                            @foreach ($contribution['ai_facts'] as $fact)
                                <li class="text-xs text-gray-700">{{ $fact }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="mt-2 text-xs text-emerald-900">{{ $contribution['installer_decides'] }}</p>
                </div>
            @endforeach
        </div>
    @endif

    @if ($subjectPhotos->isNotEmpty())
        <ul class="mt-4 grid grid-cols-4 gap-2">
            @foreach ($subjectPhotos as $photo)
                <li>
                    <a
                        href="{{ route('installer.uploads.show', [$intake, $photo]) }}"
                        target="_blank"
                        rel="noopener"
                        class="block overflow-hidden rounded-xl border border-gray-200 bg-gray-50"
                    >
                        <img
                            src="{{ route('installer.uploads.show', [$intake, $photo]) }}"
                            alt="Foto bij {{ $subject->label }}"
                            class="aspect-square w-full object-cover"
                        >
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($assumptions->isNotEmpty())
        <div class="mt-4 space-y-2">
            <p class="text-xs font-semibold uppercase tracking-[0.06em] text-amber-800">AI-voorstellen (nog bevestigen)</p>
            <ul class="space-y-2">
                @foreach ($assumptions as $assumption)
                    @php
                        $fieldLabel = is_string($assumption->value['_field_label'] ?? null)
                            ? $assumption->value['_field_label']
                            : \App\Domains\Intake\Support\TechnicalProposalCopy::fallbackFieldLabel(
                                (string) ($assumption->key ?? 'unknown')
                            );
                        $displayValue = is_string($assumption->value['_display_value'] ?? null)
                            ? $assumption->value['_display_value']
                            : '';
                        $uncertainty = is_string($assumption->value['_uncertainty'] ?? null)
                            ? $assumption->value['_uncertainty']
                            : 'Nog te beoordelen door de installateur';
                        $provenanceLabel = is_string($assumption->value['_provenance_label'] ?? null)
                            ? $assumption->value['_provenance_label']
                            : 'aanname';
                        $sourceLabel = is_string($assumption->value['_source_label'] ?? null)
                            ? $assumption->value['_source_label']
                            : 'aanname';
                        $confidenceLabel = is_string($assumption->value['_confidence_label'] ?? null)
                            ? $assumption->value['_confidence_label']
                            : null;
                        $metaBits = array_values(array_filter([
                            $provenanceLabel,
                            $sourceLabel !== $provenanceLabel ? 'bron: '.$sourceLabel : null,
                            $confidenceLabel !== null ? 'zekerheid: '.$confidenceLabel : null,
                        ]));
                    @endphp
                    <li class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2" data-testid="ai-proposal-card">
                        <p class="text-xs font-semibold text-amber-800">{{ implode(' · ', $metaBits) }}</p>
                        <p class="mt-0.5 text-sm font-medium text-gray-950" data-testid="ai-proposal-field">{{ $fieldLabel }}</p>
                        @if ($displayValue !== '')
                            <p class="mt-0.5 text-xs text-gray-700" data-testid="ai-proposal-value">{{ $displayValue }}</p>
                        @endif
                        @if ($uncertainty !== null && $uncertainty !== '')
                            <p class="mt-0.5 text-xs text-amber-900" data-testid="ai-proposal-uncertainty">{{ $uncertainty }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($photoSuggestions->isNotEmpty())
        <div class="mt-4 space-y-3">
            @foreach ($photoSuggestions as $suggestion)
                @php
                    $impactLabel = match ($suggestion->value['impact'] ?? null) {
                        'feasibility' => 'Kan de haalbaarheid beïnvloeden',
                        'materials' => 'Kan het materiaal beïnvloeden',
                        'cost' => 'Kan de prijs beïnvloeden',
                        'installation' => 'Kan de montage beïnvloeden',
                        default => 'Notitie',
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
                        @php
                            $photoAsk = $photoObservationRelevance->warrantsPhotoTask($suggestion)
                                ? app(\App\Domains\Intake\Services\ContextualCustomerTaskBuilder::class)
                                    ->forPhotoSuggestion($subject, $suggestion)
                                : null;
                        @endphp
                        @if ($photoAsk !== null)
                            <x-ask-customer-button :intake="$intake" :ask="$photoAsk" label="Vraag nieuwe foto" class="inline-flex min-h-10 items-center rounded-lg border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-800 hover:bg-indigo-50" />
                        @endif
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
                                <button class="inline-flex min-h-10 items-center rounded-lg bg-marketing-green-dark px-3 py-2 text-xs font-semibold text-white hover:bg-marketing-green">
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
            <p class="text-xs font-extrabold uppercase tracking-[0.06em] text-gray-600">Notities</p>
            @foreach ($technicalNotes as $note)
                <div class="rounded-xl bg-gray-50 px-3 py-2">
                    <p class="text-sm leading-relaxed text-gray-800">{{ $note->value['text'] ?? $note->value['_display_value'] ?? '' }}</p>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ match ($note->method) {
                            'installer_confirmed' => 'Door installateur bevestigd',
                            'installer_adjusted' => 'Door installateur aangepast en bevestigd',
                            'installer_corrected' => 'Vervangen door installateurscorrectie',
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
            @php
                $installerHardMaxBytes = (int) config('intake.uploads.hard_max_bytes', 15 * 1024 * 1024);
                $installerHardMaxMp = (float) config('intake.uploads.hard_max_megapixels', 24);
                $installerTooLarge = (string) (config('intake.uploads.too_large_message') ?: 'Deze foto is te groot. Probeer een andere foto of maak een nieuwe.');
                $installerMaxFiles = max(1, (int) config('intake.uploads.max_files_per_question', 5));
            @endphp
            <form
                method="POST"
                enctype="multipart/form-data"
                action="{{ route('intakes.workspace.photos.store', [$intake, $subject]) }}"
                class="space-y-3 border-t border-gray-200 p-3"
                data-client-downscale="1"
                data-upload-max-bytes="{{ $installerHardMaxBytes }}"
                data-upload-max-megapixels="{{ $installerHardMaxMp }}"
                data-upload-too-large="{{ $installerTooLarge }}"
                x-data="{
                    names: [],
                    onPick(event) {
                        const files = Array.from(event.target.files || []);
                        this.names = files.map((file) => file.name);
                    }
                }"
            >
                @csrf
                <div>
                    <label
                        for="{{ $fieldPrefix }}-photo"
                        class="flex min-h-24 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white px-3 text-center"
                    >
                        <span class="text-sm font-semibold text-gray-900">Camera openen of foto's kiezen</span>
                        <span class="mt-1 text-xs text-gray-500">
                            JPEG, PNG, WebP of HEIC · max {{ number_format($installerHardMaxBytes / 1048576, 0) }} MB
                            · tot {{ $installerMaxFiles }} foto's · worden automatisch verkleind
                        </span>
                    </label>
                    <input
                        id="{{ $fieldPrefix }}-photo"
                        type="file"
                        name="photo[]"
                        accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,image/*"
                        class="sr-only"
                        multiple
                        required
                        x-on:change="onPick($event)"
                    >
                    <ul
                        x-show="names.length > 0"
                        x-cloak
                        class="mt-2 space-y-1 text-xs text-gray-600"
                        data-testid="installer-photo-preview"
                    >
                        <template x-for="name in names" :key="name">
                            <li class="truncate" x-text="name"></li>
                        </template>
                    </ul>
                </div>
                @error('photo')
                    <p class="text-sm font-medium text-red-700">{{ $message }}</p>
                @enderror
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
                <button class="inline-flex min-h-10 items-center rounded-lg bg-marketing-green-dark px-3 py-2 text-xs font-semibold text-white hover:bg-marketing-green">
                    Foto's opslaan
                </button>
            </form>
        </details>

        <details class="min-w-0 basis-full rounded-xl border border-gray-200 bg-gray-50">
            <summary class="flex min-h-11 cursor-pointer list-none items-center px-3 py-2 text-sm font-semibold text-gray-800">
                Notitie toevoegen
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
                <button class="inline-flex min-h-10 items-center rounded-lg bg-marketing-green-dark px-3 py-2 text-xs font-semibold text-white hover:bg-marketing-green">
                    Notitie toevoegen
                </button>
            </form>
        </details>
    </div>
@endif
