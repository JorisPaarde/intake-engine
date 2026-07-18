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

                    <div>
                        <x-input-label for="customer_name" value="Naam klant" />
                        <x-text-input id="customer_name" name="customer_name" class="mt-1 block w-full" type="text" :value="old('customer_name')" required autofocus />
                        <x-input-error :messages="$errors->get('customer_name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="customer_email" value="E-mailadres" />
                        <x-text-input id="customer_email" name="customer_email" class="mt-1 block w-full" type="email" :value="old('customer_email')" required />
                        <p class="mt-1 text-sm text-gray-500">Hiernaar sturen we de klantlink automatisch (kopieerbare link blijft beschikbaar).</p>
                        <x-input-error :messages="$errors->get('customer_email')" class="mt-2" />
                    </div>


                    <div>
                        <x-input-label for="customer_phone" value="Telefoonnummer (optioneel)" />
                        <x-text-input id="customer_phone" name="customer_phone" class="mt-1 block w-full" type="text" :value="old('customer_phone')" />
                        <x-input-error :messages="$errors->get('customer_phone')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="address_line" value="Adres" />
                        <x-text-input id="address_line" name="address_line" class="mt-1 block w-full" type="text" :value="old('address_line')" required />
                        <x-input-error :messages="$errors->get('address_line')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="address_postal_code" value="Postcode" />
                            <x-text-input id="address_postal_code" name="address_postal_code" class="mt-1 block w-full" type="text" :value="old('address_postal_code')" />
                            <x-input-error :messages="$errors->get('address_postal_code')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="address_city" value="Plaats" />
                            <x-text-input id="address_city" name="address_city" class="mt-1 block w-full" type="text" :value="old('address_city')" />
                            <x-input-error :messages="$errors->get('address_city')" class="mt-2" />
                        </div>
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
                                Wat u hier invult, ziet de klant als voorzet en bevestigt hij zelf — zo hoeft hij het niet opnieuw op te geven.
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
                        <x-primary-button>Opslaan en klantlink mailen</x-primary-button>

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
</x-app-layout>
