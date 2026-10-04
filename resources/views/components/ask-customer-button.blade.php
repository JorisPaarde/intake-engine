@props(['intake', 'ask', 'label' => 'Vraag de klant'])

{{--
    Voegt toe aan de zichtbare conceptlijst (#demo-customer-task) met bewerkbare
    klanttekst. Versturen gebeurt pas via één ronde (max 5), niet meteen activeren.
--}}
<a
    href="{{ route('intakes.workspace.tasks.prepare', array_filter([
        'intake' => $intake,
        'type' => $ask['type'],
        'prompt' => $ask['prompt'],
        'decision_area_key' => $ask['decision_area_key'] ?? null,
        'dossier_subject_id' => $ask['dossier_subject_id'] ?? null,
    ], static fn (mixed $value): bool => $value !== null && $value !== '')) }}"
    title="{{ $ask['prompt'] }}"
    {{ $attributes->has('class') ? $attributes : $attributes->merge(['class' => 'inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50']) }}
>
    {{ $label }}
</a>
