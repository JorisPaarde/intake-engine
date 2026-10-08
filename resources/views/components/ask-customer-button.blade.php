@props(['intake', 'ask', 'label' => 'Vraag de klant'])

{{--
    Voegt toe aan de zichtbare conceptlijst (#demo-customer-task) met bewerkbare
    klanttekst. Versturen gebeurt pas via één ronde (max 5), niet meteen activeren.
    POST + CSRF: prepare mag geen side-effect via GET hebben.
    Caller class vervangt de button-defaults (geen conflicterende utilities).
--}}
<form
    method="POST"
    action="{{ route('intakes.workspace.tasks.prepare', $intake) }}"
    class="inline"
>
    @csrf
    <input type="hidden" name="type" value="{{ $ask['type'] }}">
    <input type="hidden" name="prompt" value="{{ $ask['prompt'] }}">
    @if (! empty($ask['decision_area_key']))
        <input type="hidden" name="decision_area_key" value="{{ $ask['decision_area_key'] }}">
    @endif
    @if (! empty($ask['dossier_subject_id']))
        <input type="hidden" name="dossier_subject_id" value="{{ $ask['dossier_subject_id'] }}">
    @endif
    <button
        type="submit"
        title="{{ $ask['prompt'] }}"
        {{ $attributes->has('class') ? $attributes : $attributes->merge(['class' => 'inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50']) }}
    >
        {{ $label }}
    </button>
</form>
