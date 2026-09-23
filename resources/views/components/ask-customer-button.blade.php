@props(['intake', 'ask', 'label' => 'Vraag de klant'])

{{--
    Eén klik: de app maakt de klanttaak en verstuurt hem direct via het bestaande
    quick-pad (BL-107). Parameters staan — net als voorheen bij tasks.prepare — in de
    URL; geen verborgen dossiervelden in het formulier.
--}}
<form
    method="POST"
    action="{{ route('intakes.workspace.tasks.quick', array_filter([
        'intake' => $intake,
        'type' => $ask['type'],
        'prompt' => $ask['prompt'],
        'decision_area_key' => $ask['decision_area_key'] ?? null,
        'dossier_subject_id' => $ask['dossier_subject_id'] ?? null,
    ], static fn (mixed $value): bool => $value !== null && $value !== '')) }}"
    class="contents"
>
    @csrf
    <button type="submit" title="{{ $ask['prompt'] }}" {{ $attributes->has('class') ? $attributes : $attributes->merge(['class' => 'inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50']) }}>
        {{ $label }}
    </button>
</form>
