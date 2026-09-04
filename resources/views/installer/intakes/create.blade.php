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
                        <p class="text-sm font-semibold text-gray-800">AI vult de vragen in (optioneel)</p>
                        <p class="mt-0.5 text-xs text-gray-500">Schrijf of dicteer wat de klant wil. De AI vult in wat zeker genoeg is. Alleen open vragen blijven over.</p>
                        <div class="mt-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <x-input-label for="prefill_request_reason" value="Beschrijf wat de klant wil" />
                                <x-secondary-button
                                    type="button"
                                    id="prefill-request-reason-dictate"
                                    class="gap-2 px-3"
                                    data-dictate-start="Dicteren"
                                    data-dictate-stop="Stop"
                                    aria-pressed="false"
                                    hidden
                                >
                                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 1.5a2.75 2.75 0 0 0-2.75 2.75v5a2.75 2.75 0 1 0 5.5 0v-5A2.75 2.75 0 0 0 10 1.5Z" />
                                        <path d="M5.5 8.25a.75.75 0 0 0-1.5 0 6 6 0 0 0 5.25 5.953V16.5H7.75a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5H11.75v-2.297A6 6 0 0 0 17 8.25a.75.75 0 0 0-1.5 0 4.5 4.5 0 1 1-9 0Z" />
                                    </svg>
                                    <span data-dictate-label>Dicteren</span>
                                </x-secondary-button>
                            </div>
                            <textarea
                                id="prefill_request_reason"
                                name="prefill[request_reason]"
                                rows="4"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Bijv. twee slaapkamers op zolder koelen in de zomer"
                            >{{ old('prefill.request_reason', '') }}</textarea>
                            <p id="prefill-request-reason-dictate-status" class="mt-1 text-xs text-gray-500" hidden aria-live="polite"></p>
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

    <script>
        (function () {
            const textarea = document.getElementById('prefill_request_reason');
            const button = document.getElementById('prefill-request-reason-dictate');
            const status = document.getElementById('prefill-request-reason-dictate-status');
            const label = button ? button.querySelector('[data-dictate-label]') : null;
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

            if (!textarea || !button || !status || !label || !SpeechRecognition) {
                return;
            }

            button.hidden = false;

            const startLabel = button.getAttribute('data-dictate-start') || 'Dicteren';
            const stopLabel = button.getAttribute('data-dictate-stop') || 'Stop';
            let recognition = null;
            let listening = false;
            let baseText = '';

            function setStatus(message, isError) {
                status.hidden = !message;
                status.textContent = message || '';
                status.classList.toggle('text-red-600', Boolean(isError));
                status.classList.toggle('text-gray-500', !isError);
            }

            function setListening(active) {
                listening = active;
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                label.textContent = active ? stopLabel : startLabel;
                button.classList.toggle('border-red-300', active);
                button.classList.toggle('bg-red-50', active);
                button.classList.toggle('text-red-800', active);
            }

            function fitTextarea() {
                textarea.style.height = 'auto';
                textarea.style.height = Math.min(textarea.scrollHeight, 320) + 'px';
            }

            function appendFinal(transcript) {
                const piece = String(transcript || '').trim();
                if (!piece) return;

                baseText = baseText.trim() === '' ? piece : (baseText.trim() + ' ' + piece);
                textarea.value = baseText;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                fitTextarea();
            }

            function stopListening() {
                if (!recognition) return;
                try {
                    recognition.stop();
                } catch (error) {
                    // Already stopped.
                }
            }

            function startListening() {
                recognition = new SpeechRecognition();
                recognition.lang = 'nl-NL';
                recognition.continuous = true;
                recognition.interimResults = true;
                recognition.maxAlternatives = 1;
                baseText = textarea.value || '';

                recognition.onstart = function () {
                    setListening(true);
                    setStatus('Luisteren… spreek in en tik op Stop als je klaar bent.', false);
                };

                recognition.onresult = function (event) {
                    let interim = '';

                    for (let index = event.resultIndex; index < event.results.length; index++) {
                        const result = event.results[index];
                        const transcript = result[0] ? result[0].transcript : '';

                        if (result.isFinal) {
                            appendFinal(transcript);
                        } else {
                            interim += transcript;
                        }
                    }

                    if (interim.trim() !== '') {
                        const preview = baseText.trim() === ''
                            ? interim.trim()
                            : (baseText.trim() + ' ' + interim.trim());
                        textarea.value = preview;
                        fitTextarea();
                        setStatus('Luisteren…', false);
                    }
                };

                recognition.onerror = function (event) {
                    const code = event && event.error ? String(event.error) : '';
                    let message = 'Dicteren lukte niet. Typ de tekst of probeer opnieuw.';

                    if (code === 'not-allowed' || code === 'service-not-allowed') {
                        message = 'Geen toegang tot de microfoon. Sta microfoon toe in de browser en probeer opnieuw.';
                    } else if (code === 'no-speech') {
                        message = 'Geen spraak gehoord. Tik opnieuw op Dicteren.';
                    } else if (code === 'network') {
                        message = 'Geen netwerk voor dicteren. Controleer je verbinding.';
                    } else if (code === 'aborted') {
                        message = '';
                    }

                    setListening(false);
                    setStatus(message, message !== '');
                };

                recognition.onend = function () {
                    setListening(false);
                    textarea.value = baseText;
                    if (status.textContent.indexOf('Luisteren') === 0) {
                        setStatus(baseText.trim() === '' ? '' : 'Tekst toegevoegd. Je kunt nog typen of opnieuw dicteren.', false);
                    }
                };

                try {
                    recognition.start();
                } catch (error) {
                    setListening(false);
                    setStatus('Dicteren kon niet starten in deze browser.', true);
                }
            }

            button.addEventListener('click', function () {
                if (listening) {
                    stopListening();
                    return;
                }

                startListening();
            });
        })();
    </script>
</x-app-layout>
