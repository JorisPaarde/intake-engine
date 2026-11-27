{{--
  BL-174: klantpagina “Dit hebben we gevonden” boven het versturen-vinkje.
  Verwacht: $reviewFindings (CustomerFindings::forIntake), $intake, $companionQuestions.
--}}
@php
    $correctionsQuestion = collect($companionQuestions ?? [])->firstWhere('key', 'customer_corrections');
@endphp
<div
    class="mb-5 space-y-4"
    data-testid="review-findings"
    @if ($reviewFindings['pending']) wire:poll.5s.visible @endif
    x-data="{
        noteFor(label) {
            const field = document.getElementById('field-customer_corrections');
            if (! field) { return; }
            const prefix = label + ': ';
            if (! field.value.includes(prefix)) {
                field.value = (field.value.trim() === '' ? '' : field.value.replace(/\s+$/, '') + '\n') + prefix;
            }
            field.focus();
            field.setSelectionRange(field.value.length, field.value.length);
        },
    }"
>
    @if ($reviewFindings['has_aerial'])
        <figure class="overflow-hidden rounded-xl border border-[#dde2da] bg-white shadow-sm" data-testid="review-aerial">
            <img
                src="{{ route('customer.aerial.show', ['token' => $intake->access_token]) }}"
                alt="Luchtfoto van je woning"
                class="aspect-square w-full object-cover"
                loading="lazy"
            >
            <figcaption class="px-4 py-2 text-xs text-[#5e6862]">
                Luchtfoto van {{ $intake->fullAddress() }} (noorden is boven)
            </figcaption>
        </figure>
    @endif

    @if ($reviewFindings['pending'])
        <p class="rounded-xl border border-[#dde2da] bg-[#f7f8f6] px-4 py-3 text-sm text-[#414b45]" data-testid="review-pending">
            We bekijken je foto’s en tekst nog. Wat we vinden, verschijnt hier vanzelf. Je kunt ook nu al versturen.
        </p>
    @endif

    @foreach ($reviewFindings['groups'] as $group)
        <section class="rounded-xl border border-[#dde2da] bg-white px-4 py-3 shadow-sm" data-testid="review-group">
            <h2 class="text-sm font-semibold text-[#18201d]">{{ $group['title'] }}</h2>
            <dl class="mt-2 divide-y divide-[#eef1ec]">
                @foreach ($group['items'] as $item)
                    <div class="py-2" wire:key="finding-{{ $item['key'] }}" data-testid="finding-{{ $item['key'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-sm text-[#5e6862]">{{ $item['label'] }}</dt>
                            <dd class="max-w-[62%] text-right text-sm font-medium text-[#18201d]">{{ $item['value'] }}</dd>
                        </div>
                        <div class="mt-0.5 flex items-center justify-between gap-3 text-xs text-[#5e6862]">
                            <span>{{ $item['source'] }}</span>
                            @if ($correctionsQuestion)
                                <button
                                    type="button"
                                    class="font-medium text-[var(--tenant-primary)] underline decoration-[var(--tenant-primary)]/40 underline-offset-2"
                                    x-on:click="noteFor(@js($item['label']))"
                                >Klopt niet?</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </dl>
        </section>
    @endforeach

    @if ($reviewFindings['photos'] !== [])
        <section class="rounded-xl border border-[#dde2da] bg-white px-4 py-3 shadow-sm" data-testid="review-photos">
            <h2 class="text-sm font-semibold text-[#18201d]">Je foto’s</h2>
            <ul class="mt-2 grid grid-cols-3 gap-2">
                @foreach ($reviewFindings['photos'] as $tile)
                    <li wire:key="review-photo-{{ $tile['upload']->id }}">
                        <img
                            src="{{ route('customer.uploads.show', ['token' => $intake->access_token, 'upload' => $tile['upload']]) }}"
                            alt="{{ $tile['caption'] }}"
                            class="aspect-square w-full rounded-lg object-cover"
                            loading="lazy"
                        >
                        <p class="mt-1 truncate text-xs text-[#5e6862]">{{ $tile['caption'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($correctionsQuestion)
        <div class="rounded-xl border border-[#dde2da] bg-white px-4 py-3 shadow-sm">
            <label for="field-customer_corrections" class="text-sm font-semibold text-[#18201d]">{{ $correctionsQuestion->label }}</label>
            <p class="mt-1 text-xs text-[#5e6862]">Optioneel. Je installateur kijkt hiernaar.</p>
            <textarea
                id="field-customer_corrections"
                rows="3"
                wire:model.blur="form.customer_corrections.text"
                class="mt-2 block w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                placeholder="Bijv. Ruimte 2: dat is een werkkamer, geen slaapkamer"
                data-testid="customer-corrections"
            ></textarea>
        </div>
    @endif
</div>
