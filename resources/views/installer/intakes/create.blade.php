<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Nieuwe opname
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
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
                                        <span class="mt-1 block text-xs leading-5 text-gray-600">De klant krijgt direct een beveiligde link met begeleide foto- en informatieopdrachten.</span>
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
                                        <span class="mt-1 block text-xs leading-5 text-gray-600">Open direct de mobiele werkplek. De klanttoegang blijft uit en er wordt geen link verstuurd.</span>
                                    </span>
                                </span>
                            </label>
                        </div>
                        <x-input-error :messages="$errors->get('workflow_mode')" class="mt-2" />
                    </fieldset>

                    <fieldset
                        class="rounded-2xl border border-gray-200 bg-gray-50/70 p-5"
                        data-address-lookup
                        data-endpoint="{{ route('address-suggestions') }}"
                    >
                        <legend class="px-2 text-sm font-semibold text-gray-900">Adres van de opname</legend>
                        <p class="mb-4 text-sm text-gray-600">Vul eerst postcode en huisnummer in. Straat en plaats worden daarna automatisch aangevuld.</p>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-12">
                            <div class="sm:col-span-5">
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
                            <div class="sm:col-span-4">
                                <x-input-label for="address_house_number" value="Huisnummer" />
                                <x-text-input
                                    id="address_house_number"
                                    name="address_house_number"
                                    class="mt-1 block w-full"
                                    type="number"
                                    :value="old('address_house_number')"
                                    autocomplete="address-line2"
                                    min="1"
                                    max="999999"
                                    required
                                />
                                <x-input-error :messages="$errors->get('address_house_number')" class="mt-2" />
                            </div>
                            <div class="sm:col-span-3">
                                <x-input-label for="address_house_number_addition" value="Toevoeging" />
                                <x-text-input
                                    id="address_house_number_addition"
                                    name="address_house_number_addition"
                                    class="mt-1 block w-full"
                                    type="text"
                                    :value="old('address_house_number_addition')"
                                    maxlength="20"
                                    placeholder="A"
                                />
                                <x-input-error :messages="$errors->get('address_house_number_addition')" class="mt-2" />
                            </div>
                        </div>

                        <p data-address-status class="mt-4 text-sm text-gray-600" role="status" aria-live="polite"></p>

                        <input id="address_lookup_id" name="address_lookup_id" type="hidden" value="{{ old('address_lookup_id') }}">
                        <ul
                            id="address-suggestions"
                            data-address-suggestions
                            class="mt-3 hidden overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
                            aria-label="Gevonden adressen"
                        ></ul>
                        <x-input-error :messages="$errors->get('address_lookup_id')" class="mt-2" />

                        <details
                            data-manual-address
                            class="mt-4 rounded-xl border border-gray-200 bg-white p-4"
                            @if (old('address_line') || $errors->has('address_line') || $errors->has('address_city')) open @endif
                        >
                            <summary class="cursor-pointer text-sm font-semibold text-gray-800">Handmatig invoeren</summary>
                            <p class="mt-2 text-xs text-gray-500">Controleer het aangevulde adres. U kunt straat en plaats indien nodig handmatig aanpassen.</p>
                            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                        </details>
                    </fieldset>

                    <div>
                        <x-input-label for="customer_name" value="Naam klant" />
                        <x-text-input id="customer_name" name="customer_name" class="mt-1 block w-full" type="text" :value="old('customer_name')" required />
                        <x-input-error :messages="$errors->get('customer_name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="customer_email" value="E-mailadres" />
                        <x-text-input id="customer_email" name="customer_email" class="mt-1 block w-full" type="email" :value="old('customer_email')" required />
                        <p class="mt-1 text-sm text-gray-500" data-customer-email-help>Bij een klantopname sturen we de beveiligde link automatisch. Bij zelf opnemen wordt niets verstuurd.</p>
                        <x-input-error :messages="$errors->get('customer_email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="customer_phone" value="Telefoonnummer (optioneel)" />
                        <x-text-input id="customer_phone" name="customer_phone" class="mt-1 block w-full" type="text" :value="old('customer_phone')" />
                        <x-input-error :messages="$errors->get('customer_phone')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="internal_note" value="Interne notitie (optioneel)" />
                        <textarea id="internal_note" name="internal_note" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('internal_note') }}</textarea>
                        <x-input-error :messages="$errors->get('internal_note')" class="mt-2" />
                    </div>

                    @if (! empty($prefillQuestionsByTemplate))
                        <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                            <p class="text-sm font-semibold text-gray-800">Alvast invullen (optioneel)</p>
                            <p class="mt-1 text-xs text-gray-500">
                                Bekende gegevens worden direct in het dossier gezet. Alleen een beslissende onzekerheid wordt later nog gericht voorgelegd.
                            </p>

                            @foreach ($prefillQuestionsByTemplate as $templateKey => $questions)
                                <div
                                    data-prefill-block="{{ $templateKey }}"
                                    class="mt-4 space-y-4 {{ old('template_key', 'airco') === $templateKey ? '' : 'hidden' }}"
                                >
                                    @foreach ($questions as $question)
                                        @include('installer.intakes._prefill-field', ['question' => $question])
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">Annuleren</a>
                        <x-primary-button data-submit-label>Opslaan en klantlink mailen</x-primary-button>

                    </div>
                </form>
            </div>
        </div>
    </div>

    @if (! empty($prefillQuestionsByTemplate))
        <script>
            (function () {
                const select = document.getElementById('template_key');
                const blocks = document.querySelectorAll('[data-prefill-block]');
                if (!select || blocks.length === 0) return;

                function sync() {
                    blocks.forEach(function (block) {
                        const active = block.getAttribute('data-prefill-block') === select.value;
                        block.classList.toggle('hidden', !active);
                        // Disabled inputs are not submitted — keeps hidden templates' answers out.
                        block.querySelectorAll('input, select, textarea').forEach(function (field) {
                            field.disabled = !active;
                        });
                    });
                }

                select.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endif

    <script>
        (function () {
            const workflowOptions = document.querySelectorAll('input[name="workflow_mode"]');
            const submitLabel = document.querySelector('[data-submit-label]');

            function syncWorkflow() {
                if (!submitLabel) return;
                const selected = document.querySelector('input[name="workflow_mode"]:checked');
                submitLabel.textContent = selected?.value === '{{ \App\Enums\ContributionMode::Installer->value }}'
                    ? 'Opname starten'
                    : 'Opslaan en klantlink mailen';
            }

            workflowOptions.forEach(function (option) {
                option.addEventListener('change', syncWorkflow);
            });
            syncWorkflow();

            const root = document.querySelector('[data-address-lookup]');
            const postalCode = document.getElementById('address_postal_code');
            const houseNumber = document.getElementById('address_house_number');
            const addition = document.getElementById('address_house_number_addition');
            const status = root?.querySelector('[data-address-status]');
            const list = root?.querySelector('[data-address-suggestions]');
            const manual = root?.querySelector('[data-manual-address]');
            const manualSummary = manual?.querySelector('summary');
            const addressLine = document.getElementById('address_line');
            const city = document.getElementById('address_city');
            const lookupId = document.getElementById('address_lookup_id');

            if (!root || !postalCode || !houseNumber || !addition || !status || !list || !manual || !manualSummary || !addressLine || !city || !lookupId) return;

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
                manualSummary.textContent = 'Handmatig invoeren';
                closeSuggestions();
                setStatus('', false);
            }

            function selectSuggestion(suggestion) {
                addressLine.value = suggestion.address_line;
                postalCode.value = formattedPostalCode(suggestion.postal_code);
                city.value = suggestion.city;
                lookupId.value = suggestion.id;
                manual.open = true;
                manualSummary.textContent = 'Gevonden adres';
                closeSuggestions();
                setStatus('Adres gevonden en aangevuld.', false);
            }

            function showSuggestions(suggestions) {
                closeSuggestions();

                if (suggestions.length === 0) {
                    manual.open = true;
                    setStatus('Geen adres gevonden. Vul straat en plaats handmatig in.', true);
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
                const normalizedHouseNumber = houseNumber.value.trim();

                if (!/^[1-9]\d{3}[A-Z]{2}$/.test(normalizedPostalCode)) return;

                if (!/^\d+$/.test(normalizedHouseNumber) || Number(normalizedHouseNumber) < 1) return;

                if (request) request.abort();
                const activeRequest = new AbortController();
                request = activeRequest;
                root.setAttribute('aria-busy', 'true');
                closeSuggestions();
                setStatus('Adres zoeken…', false);

                try {
                    const url = new URL(root.dataset.endpoint, window.location.origin);
                    url.searchParams.set('postal_code', formattedPostalCode(normalizedPostalCode));
                    url.searchParams.set('house_number', normalizedHouseNumber);
                    if (addition.value.trim() !== '') {
                        url.searchParams.set('house_number_addition', addition.value.trim());
                    }

                    const response = await fetch(url, {
                        headers: { 'Accept': 'application/json' },
                        signal: activeRequest.signal,
                    });
                    const payload = await response.json();

                    if (request !== activeRequest) return;

                    if (!response.ok) {
                        manual.open = true;
                        setStatus(payload.message || 'Adres zoeken is niet gelukt. Vul het adres handmatig in.', true);
                        return;
                    }

                    showSuggestions(Array.isArray(payload.data) ? payload.data : []);
                } catch (error) {
                    if (error.name !== 'AbortError' && request === activeRequest) {
                        manual.open = true;
                        setStatus('De adresservice is tijdelijk niet beschikbaar. Vul het adres handmatig in.', true);
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
                manualSummary.textContent = 'Handmatig ingevoerd';
                closeSuggestions();
                setStatus('Adres handmatig aangepast.', false);
            }

            function scheduleAddressSearch() {
                clearSelectedAddress();

                const normalizedPostalCode = postalCode.value.toUpperCase().replace(/\s+/g, '');
                const normalizedHouseNumber = houseNumber.value.trim();
                const canSearch = /^[1-9]\d{3}[A-Z]{2}$/.test(normalizedPostalCode)
                    && /^\d+$/.test(normalizedHouseNumber)
                    && Number(normalizedHouseNumber) >= 1;

                if (!canSearch) return;

                setStatus('Adres wordt automatisch gezocht…', false);
                searchTimer = window.setTimeout(function () {
                    searchTimer = null;
                    searchAddress();
                }, 350);
            }

            [postalCode, houseNumber, addition].forEach(function (field) {
                field.addEventListener('input', scheduleAddressSearch);
            });

            postalCode.addEventListener('blur', function () {
                postalCode.value = formattedPostalCode(postalCode.value);
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
