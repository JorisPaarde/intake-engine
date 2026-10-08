@props([
    'id',
    'title',
    'body',
    'action',
    'confirmLabel',
    'cancelLabel' => 'Annuleren',
])

{{--
    Eigen bevestigingsdialoog in huisstijl (BL-147, UX #15.10). Open met een knop of link
    met data-confirm-dialog-open="{{ $id }}". Focus start op Annuleren; Esc of klikken
    buiten de dialoog annuleert (resources/js/app.js). Herbruikbaar voor andere
    destructieve acties (bijv. klantlink intrekken).
--}}
<dialog
    id="{{ $id }}"
    aria-labelledby="{{ $id }}-title"
    aria-describedby="{{ $id }}-body"
    data-confirm-dialog
    {{ $attributes->merge(['class' => 'w-[calc(100%-2rem)] max-w-md rounded-2xl border border-[#dde2da] bg-white p-0 text-left text-[#18201d] shadow-xl backdrop:bg-[#18201d]/50']) }}
>
    <form method="POST" action="{{ $action }}" class="p-6">
        @csrf
        <h2 id="{{ $id }}-title" class="text-lg font-semibold text-[#18201d]">{{ $title }}</h2>
        <p id="{{ $id }}-body" class="mt-2 text-sm leading-relaxed text-[#414b45]">{{ $body }}</p>
        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <x-secondary-button data-confirm-dialog-cancel autofocus>{{ $cancelLabel }}</x-secondary-button>
            <x-danger-button>{{ $confirmLabel }}</x-danger-button>
        </div>
    </form>
</dialog>
