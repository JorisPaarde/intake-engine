@php
    $isPublicDemo = (bool) ($isPublicDemo ?? false);
    $demoDefaults = is_array($demoDefaults ?? null) ? $demoDefaults : [];
    $demoAddressExample = is_array($demoAddressExample ?? null) ? $demoAddressExample : null;
    $demoValue = static function (string $key, mixed $fallback = null) use ($demoDefaults): mixed {
        return old($key, $demoDefaults[$key] ?? $fallback);
    };
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $isPublicDemo ? 'Nieuwe demo-opname' : 'Nieuwe opname' }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if ($isPublicDemo)
                <div class="mb-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                    Vul zelf een klantnaam, postcode en huisnummer in — net als na een echte aanvraag. Je ziet meteen straat en plaats. Na opslaan vult de app bekende woninggegevens aan en leest de korte uitleg mee. Gebruik fictieve klantgegevens. Er gaat geen e-mail uit.
                </div>
            @endif
            <div class="bg-white shadow-sm sm:rounded-lg p-6" data-demo-anchor="create-form">
                <form method="POST" action="{{ route('intakes.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="template_key" value="Type opname" />
                        <select id="template_key" name="template_key" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                            @foreach ($templates as $template)
                                <option value="{{ $template->key }}" @selected(old('template_key', 'airco') === $template->key)>
                                    {{ $template->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('template_key')" class="mt-2" />
                    </div>

                    @if ($isPublicDemo)
                        <input type="hidden" name="workflow_mode" value="{{ \App\Enums\ContributionMode::Customer->value }}">
                    @else
                        <fieldset>
                            <legend class="text-sm font-medium text-gray-700">Wie voert de opname uit?</legend>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                <label class="cursor-pointer rounded-2xl border border-gray-200 bg-white p-4 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                    <span class="flex items-start gap-3">
                                        <input
                                            type="radio"
                                            name="workflow_mode"
                                            value="{{ \App\Enums\ContributionMode::Customer->value }}"
                                            class="mt-1 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            @checked(old('workflow_mode', \App\Enums\ContributionMode::Customer->value) === \App\Enums\ContributionMode::Customer->value)
                                        >
                                        <span>
                                            <span class="block text-sm font-semibold text-gray-900">Klant laten opnemen</span>
                                            <span class="mt-1 block text-xs leading-5 text-gray-600">De klant krijgt meteen een link. Daarin staan duidelijke stappen en foto-opdrachten.</span>
                                        </span>
                                    </span>
                                </label>
                                <label class="cursor-pointer rounded-2xl border border-gray-200 bg-white p-4 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                    <span class="flex items-start gap-3">
                                        <input
                                            type="radio"
                                            name="workflow_mode"
                                            value="{{ \App\Enums\ContributionMode::Installer->value }}"
                                            class="mt-1 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            @checked(old('workflow_mode') === \App\Enums\ContributionMode::Installer->value)
                                        >
                                        <span>
                                            <span class="block text-sm font-semibold text-gray-900">Zelf de opname uitvoeren</span>
                                            <span class="mt-1 block text-xs leading-5 text-gray-600">Je gaat meteen zelf aan de slag. De klant krijgt geen link.</span>
                                        </span>
                                    </span>
                                </label>
                            </div>
                            <x-input-error :messages="$errors->get('workflow_mode')" class="mt-2" />
                        </fieldset>
                    @endif

                    <fieldset
                        class="rounded-2xl border border-gray-200 bg-gray-50/70 p-5"
                        data-address-lookup
                        data-endpoint="{{ route('address-suggestions') }}"
                    >
                        <legend class="px-2 text-sm font-semibold text-gray-900">Adres van de opname</legend>
                        <p class="mb-4 text-sm text-gray-600">Vul eerst postcode en huisnummer in. Straat en plaats worden daarna automatisch aangevuld.</p>
                        @if ($isPublicDemo && $demoAddressExample)
                            <p class="mb-4 rounded-lg border border-sky-100 bg-white px-3 py-2 text-xs leading-relaxed text-sky-950/80">
                                Tip om te proberen:
                                <span class="font-semibold">{{ $demoAddressExample['postal_code'] }}</span>
                                +
                                <span class="font-semibold">{{ $demoAddressExample['house_number'] }}</span>
                                ({{ $demoAddressExample['line'] }}, {{ $demoAddressExample['city'] }}).
                                Je mag ook een ander bestaand adres gebruiken.
                            </p>
                        @endif

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="address_postal_code" value="Postcode" />
                                <x-text-input
                                    id="address_postal_code"
                                    name="address_postal_code"
                                    class="mt-1 block w-full uppercase"
                                    type="text"
                                    :value="old('address_postal_code')"
                                    autocomplete="postal-code"
                                    inputmode="text"
                                    maxlength="7"
                                    placeholder="1234 AB"
                                    required
                                    autofocus
                                />
                                <x-input-error :messages="$errors->get('address_postal_code')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="address_house_number" value="Huisnummer" />
                                <x-text-input
                                    id="address_house_number"
                                    name="address_house_number"
                                    class="mt-1 block w-full"
                                    type="text"
                                    :value="old('address_house_number_addition') ? (old('address_house_number').old('address_house_number_addition')) : old('address_house_number')"
                                    autocomplete="address-line2"
                                    inputmode="text"
                                    maxlength="30"
                                    placeholder="12 of 12A"
                                    required
                                />
                                <x-input-error :messages="$errors->get('address_house_number')" class="mt-2" />
                                <x-input-error :messages="$errors->get('address_house_number_addition')" class="mt-2" />
                            </div>
                        </div>

                        {{-- Toevoeging blijft in de API/DB; niet zichtbaar (zit in huisnummer + straatregel). --}}
                        <input
                            id="address_house_number_addition"
                            name="address_house_number_addition"
                            type="hidden"
                            value="{{ old('address_house_number_addition') }}"
                        >

                        <p data-address-status class="mt-3 min-h-[1.25rem] text-sm text-gray-600" role="status" aria-live="polite"></p>

                        <input id="address_lookup_id" name="address_lookup_id" type="hidden" value="{{ old('address_lookup_id') }}">
                        <ul
                            id="address-suggestions"
                            data-address-suggestions
                            class="mt-2 hidden overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
                            aria-label="Gevonden adressen"
                        ></ul>
                        <x-input-error :messages="$errors->get('address_lookup_id')" class="mt-2" />

                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label for="address_line" value="Straat en huisnummer" />
                                <x-text-input
                                    id="address_line"
                                    name="address_line"
                                    class="mt-1 block w-full"
                                    type="text"
                                    :value="old('address_line')"
                                    autocomplete="street-address"
                                    required
                                />
                                <x-input-error :messages="$errors->get('address_line')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="address_city" value="Plaats" />
                                <x-text-input
                                    id="address_city"
                                    name="address_city"
                                    class="mt-1 block w-full"
                                    type="text"
                                    :value="old('address_city')"
                                    autocomplete="address-level2"
                                    required
                                />
                                <x-input-error :messages="$errors->get('address_city')" class="mt-2" />
                            </div>
                        </div>
                    </fieldset>

                    <div>
                        <x-input-label for="customer_name" value="Naam klant" />
                        <x-text-input
                            id="customer_name"
                            name="customer_name"
                            class="mt-1 block w-full"
                            type="text"
                            :value="$demoValue('customer_name')"
                            :placeholder="$isPublicDemo && $demoAddressExample ? ('Bijv. '.$demoAddressExample['customer_name']) : null"
                            required
                        />
                        <x-input-error :messages="$errors->get('customer_name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="customer_email" value="E-mailadres" />
                        <x-text-input
                            id="customer_email"
                            name="customer_email"
                            class="mt-1 block w-full"
                            type="email"
                            :value="old('customer_email')"
                            :required="! $isPublicDemo"
                        />
                        <p class="mt-1 text-sm text-gray-500" data-customer-email-help>
                            @if ($isPublicDemo)
                                In de demo sturen we geen e-mail. Adresinvulling en AI werken wel. Na opslaan kies je hoe je verdergaat.
                            @else
                                Bij een klantopname sturen we de link automatisch. Bij zelf opnemen sturen we niets.
                            @endif
                        </p>
                        <x-input-error :messages="$errors->get('customer_email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="customer_phone" value="Telefoonnummer (optioneel)" />
                        <x-text-input id="customer_phone" name="customer_phone" class="mt-1 block w-full" type="text" :value="old('customer_phone')" />
                        <x-input-error :messages="$errors->get('customer_phone')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="internal_note" value="Interne notitie (optioneel)" />
                        <textarea id="internal_note" name="internal_note" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ $demoValue('internal_note', '') }}</textarea>
                        <x-input-error :messages="$errors->get('internal_note')" class="mt-2" />
                    </div>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                        <p class="text-sm font-semibold text-gray-800">Alvast invullen (optioneel)</p>
                        <p class="mt-0.5 text-xs text-gray-500">Noteer kort wat de klant vroeg. De app haalt daaruit wat zij al kan invullen. Jij maakt de opname aan en stuurt daarna de link, of je gaat zelf verder.</p>
                        <div class="mt-2">
                            <x-input-label for="prefill_request_reason" value="Wat vroeg de klant?" />
                            <textarea
                                id="prefill_request_reason"
                                name="prefill[request_reason]"
                                rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Bijv. twee slaapkamers op zolder koelen"
                            >{{ old('prefill.request_reason', '') }}</textarea>
                            <x-input-error :messages="$errors->get('prefill.request_reason')" class="mt-1" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">Annuleren</a>
                        <x-primary-button data-submit-label>
                            {{ $isPublicDemo ? 'Opname aanmaken' : 'Opslaan en link mailen' }}
                        </x-primary-button>

                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function () {
            @unless ($isPublicDemo)
            const workflowOptions = document.querySelectorAll('input[name="workflow_mode"]');
            const submitLabel = document.querySelector('[data-submit-label]');

            function syncWorkflow() {
                if (!submitLabel) return;
                const selected = document.querySelector('input[name="workflow_mode"]:checked');
                if (!selected) return;
                submitLabel.textContent = selected.value === '{{ \App\Enums\ContributionMode::Installer->value }}'
                    ? 'Opname starten'
                    : 'Opslaan en link mailen';
            }

            workflowOptions.forEach(function (option) {
                option.addEventListener('change', syncWorkflow);
            });
            syncWorkflow();
            @endunless

            const root = document.querySelector('[data-address-lookup]');
            const postalCode = document.getElementById('address_postal_code');
            const houseNumber = document.getElementById('address_house_number');
            const addition = document.getElementById('address_house_number_addition');
            const status = root?.querySelector('[data-address-status]');
            const list = root?.querySelector('[data-address-suggestions]');
            const addressLine = document.getElementById('address_line');
            const city = document.getElementById('address_city');
            const lookupId = document.getElementById('address_lookup_id');

            if (!root || !postalCode || !houseNumber || !addition || !status || !list || !addressLine || !city || !lookupId) return;

            let request = null;
            let searchTimer = null;

            function closeSuggestions() {
                list.replaceChildren();
                list.classList.add('hidden');
            }

            function setStatus(message, isError) {
                status.textContent = message;
                status.classList.toggle('text-red-700', Boolean(isError));
                status.classList.toggle('text-gray-600', !isError);
            }

            function formattedPostalCode(value) {
                const normalized = value.toUpperCase().replace(/\s+/g, '');
                return normalized.length === 6
                    ? normalized.slice(0, 4) + ' ' + normalized.slice(4)
                    : value;
            }

            /** @returns {{ number: string, addition: string }|null} */
            function parseHouseNumber(value) {
                const trimmed = String(value || '').trim();
                const match = trimmed.match(/^(\d+)\s*[-]?\s*([A-Za-z0-9][A-Za-z0-9\-\s]*)?$/i);
                if (!match) return null;
                const parsedAddition = (match[2] || '').trim().toUpperCase().replace(/\s+/g, '-');
                return { number: match[1], addition: parsedAddition };
            }

            function displayHouseNumber(number, houseAddition) {
                const suffix = String(houseAddition || '').trim();
                return suffix === '' ? String(number) : String(number) + suffix;
            }

            function cancelActiveRequest() {
                if (searchTimer) {
                    window.clearTimeout(searchTimer);
                    searchTimer = null;
                }

                if (!request) return;

                request.abort();
                request = null;
                root.removeAttribute('aria-busy');
            }

            function clearSelectedAddress() {
                cancelActiveRequest();

                if (lookupId.value !== '') {
                    addressLine.value = '';
                    city.value = '';
                }
                lookupId.value = '';
                closeSuggestions();
                setStatus('', false);
            }

            function selectSuggestion(suggestion) {
                const suggestionAddition = suggestion.house_number_addition || '';
                addressLine.value = suggestion.address_line;
                postalCode.value = formattedPostalCode(suggestion.postal_code);
                houseNumber.value = displayHouseNumber(suggestion.house_number, suggestionAddition);
                addition.value = suggestionAddition;
                city.value = suggestion.city;
                lookupId.value = suggestion.id;
                closeSuggestions();
                setStatus('', false);
            }

            function showSuggestions(suggestions) {
                closeSuggestions();

                if (suggestions.length === 0) {
                    setStatus('Geen adres gevonden. Vul straat en plaats zelf in.', true);
                    return;
                }

                if (suggestions.length === 1) {
                    selectSuggestion(suggestions[0]);
                    return;
                }

                suggestions.forEach(function (suggestion) {
                    const item = document.createElement('li');
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'block w-full border-b border-gray-100 px-4 py-3 text-left text-sm text-gray-800 last:border-b-0 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none';
                    button.textContent = suggestion.label;
                    button.addEventListener('click', function () { selectSuggestion(suggestion); });
                    item.appendChild(button);
                    list.appendChild(item);
                });

                list.classList.remove('hidden');
                setStatus('Meerdere adressen gevonden. Kies het juiste adres.', false);
            }

            async function searchAddress() {
                const normalizedPostalCode = postalCode.value.toUpperCase().replace(/\s+/g, '');
                const parsed = parseHouseNumber(houseNumber.value);

                if (!/^[1-9]\d{3}[A-Z]{2}$/.test(normalizedPostalCode) || !parsed) return;

                addition.value = parsed.addition;

                if (request) request.abort();
                const activeRequest = new AbortController();
                request = activeRequest;
                root.setAttribute('aria-busy', 'true');
                closeSuggestions();
                setStatus('Adres zoeken…', false);

                try {
                    const url = new URL(root.dataset.endpoint, window.location.origin);
                    url.searchParams.set('postal_code', formattedPostalCode(normalizedPostalCode));
                    url.searchParams.set('house_number', parsed.number);
                    if (parsed.addition !== '') {
                        url.searchParams.set('house_number_addition', parsed.addition);
                    }

                    const response = await fetch(url, {
                        headers: { 'Accept': 'application/json' },
                        signal: activeRequest.signal,
                    });
                    const payload = await response.json();

                    if (request !== activeRequest) return;

                    if (!response.ok) {
                        setStatus(payload.message || 'Adres zoeken is niet gelukt. Vul straat en plaats zelf in.', true);
                        return;
                    }

                    showSuggestions(Array.isArray(payload.data) ? payload.data : []);
                } catch (error) {
                    if (error.name !== 'AbortError' && request === activeRequest) {
                        setStatus('De adresservice is tijdelijk niet beschikbaar. Vul straat en plaats zelf in.', true);
                    }
                } finally {
                    if (request === activeRequest) {
                        root.removeAttribute('aria-busy');
                        request = null;
                    }
                }
            }

            function markAddressAsManuallyEdited() {
                cancelActiveRequest();
                lookupId.value = '';
                closeSuggestions();
            }

            function scheduleAddressSearch() {
                clearSelectedAddress();

                const normalizedPostalCode = postalCode.value.toUpperCase().replace(/\s+/g, '');
                const parsed = parseHouseNumber(houseNumber.value);
                const canSearch = /^[1-9]\d{3}[A-Z]{2}$/.test(normalizedPostalCode)
                    && parsed !== null
                    && Number(parsed.number) >= 1;

                if (!canSearch) return;

                addition.value = parsed.addition;
                setStatus('Adres wordt automatisch gezocht…', false);
                searchTimer = window.setTimeout(function () {
                    searchTimer = null;
                    searchAddress();
                }, 350);
            }

            [postalCode, houseNumber].forEach(function (field) {
                field.addEventListener('input', scheduleAddressSearch);
            });

            postalCode.addEventListener('blur', function () {
                postalCode.value = formattedPostalCode(postalCode.value);
            });
            houseNumber.addEventListener('blur', function () {
                const parsed = parseHouseNumber(houseNumber.value);
                if (!parsed) return;
                houseNumber.value = displayHouseNumber(parsed.number, parsed.addition);
                addition.value = parsed.addition;
            });
            addressLine.addEventListener('input', markAddressAsManuallyEdited);
            city.addEventListener('input', markAddressAsManuallyEdited);
            list.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeSuggestions();
                    postalCode.focus();
                }
            });
        })();
    </script>
</x-app-layout>
