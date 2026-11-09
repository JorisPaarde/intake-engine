<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold leading-tight text-[#18201d]">
                Bedrijfsinstellingen
            </h2>
            <p class="text-sm text-[#5e6862]">Naam, logo, actiekleur en formulier op je website.</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-xl border border-emerald-200 bg-white px-4 py-3 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('company.settings.update') }}" enctype="multipart/form-data" class="space-y-6 rounded-xl border border-[#dde2da] bg-white p-6 shadow-sm">
                @csrf
                @method('PATCH')

                <div>
                    <x-input-label for="name" value="Bedrijfsnaam" />
                    <x-text-input id="name" name="name" class="mt-1 block w-full" type="text" :value="old('name', $company->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="logo" value="Logo" />
                    <div class="mt-2 flex items-center gap-4">
                        @if ($company->hasLogo())
                            <img src="{{ route('company.logo.show', $company) }}" alt="{{ $company->name }}" class="h-14 w-14 rounded-xl border border-[#dde2da] object-contain">
                        @else
                            <div class="flex h-14 w-14 items-center justify-center rounded-xl border border-[#dde2da] bg-[#eef1ec] text-sm font-semibold text-[#5e6862]">
                                {{ mb_strtoupper(mb_substr($company->name, 0, 1)) }}
                            </div>
                        @endif
                        <input id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-[#414b45] file:mr-4 file:min-h-11 file:rounded-xl file:border-0 file:bg-[var(--tenant-primary)] file:px-4 file:text-sm file:font-semibold file:text-[var(--tenant-on-primary)]">
                    </div>
                    <p class="mt-2 text-sm text-[#5e6862]">JPEG, PNG of WebP. Maximaal 2 MB.</p>
                    <x-input-error :messages="$errors->get('logo')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="primary_color" value="Primaire kleur (optioneel)" />
                    <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <input id="primary_color" name="primary_color" type="text" value="{{ old('primary_color', $company->primary_color) }}" placeholder="#15392f" class="block min-h-11 w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)] sm:max-w-48">
                        <span class="inline-flex h-11 w-20 rounded-xl border border-[#dde2da]" style="background-color: {{ $company->themeTokens()['primary'] }}"></span>
                    </div>
                    <p class="mt-2 text-sm text-[#5e6862]">Laat leeg om bij een nieuw logo automatisch een veilige kleur te kiezen.</p>
                    <x-input-error :messages="$errors->get('primary_color')" class="mt-2" />
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-[#E5E5EA] pt-6">
                    <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-semibold text-[#414b45] hover:text-[#18201d] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2">Annuleren</a>
                    <x-primary-button>Opslaan</x-primary-button>
                </div>
            </form>

            <section class="space-y-6 rounded-xl border border-[#dde2da] bg-white p-6 shadow-sm" aria-labelledby="embed-settings-heading">
                <div class="space-y-1">
                    <h3 id="embed-settings-heading" class="text-lg font-semibold text-[#18201d]">Formulier op je website</h3>
                    <p class="text-sm text-[#5e6862]">
                        Plak de snippet in een HTML-blok op je site. De klant start een opname en gaat door naar Intake Engine.
                    </p>
                </div>

                <div class="space-y-3">
                    <x-input-label value="Publieke sleutel" />
                    @if ($company->hasEmbedPublicKey())
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <code class="block min-h-11 flex-1 overflow-x-auto rounded-xl border border-[#dde2da] bg-[#f7f8f6] px-3 py-3 text-sm text-[#18201d]" data-testid="embed-public-key">{{ $company->embed_public_key }}</code>
                            <x-secondary-button type="button" data-confirm-dialog-open="embed-rotate-key-dialog">
                                Sleutel vernieuwen
                            </x-secondary-button>
                        </div>
                        <p class="text-sm text-[#5e6862]">Dit is geen geheim. Alleen sites die jij hieronder zet, mogen het formulier gebruiken.</p>
                        <x-confirm-dialog
                            id="embed-rotate-key-dialog"
                            title="Sleutel vernieuwen?"
                            body="Je krijgt een nieuwe sleutel. Vervang daarna de snippet op je website, anders werkt het oude formulier niet meer."
                            :action="route('company.settings.embed.key.rotate')"
                            confirm-label="Sleutel vernieuwen"
                        />
                    @else
                        <form method="POST" action="{{ route('company.settings.embed.key.generate') }}">
                            @csrf
                            <x-primary-button type="submit">Sleutel maken</x-primary-button>
                        </form>
                    @endif
                    <x-input-error :messages="$errors->get('embed_public_key')" class="mt-2" />
                </div>

                @if ($embedSnippet)
                    <div class="space-y-2" x-data="{ copied: false }">
                        <x-input-label value="Snippet voor je website" />
                        <textarea
                            id="embed-snippet"
                            class="mt-1 block w-full rounded-xl border-[#dde2da] font-mono text-xs text-[#18201d] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                            rows="4"
                            readonly
                            data-testid="embed-snippet"
                        >{{ $embedSnippet }}</textarea>
                        <div class="flex flex-wrap items-center gap-3">
                            <x-secondary-button
                                type="button"
                                x-on:click="navigator.clipboard.writeText(document.getElementById('embed-snippet').value); copied = true; setTimeout(() => copied = false, 2000)"
                            >
                                Snippet kopiëren
                            </x-secondary-button>
                            <span class="text-sm text-emerald-700" x-show="copied" x-cloak>Gekopieerd.</span>
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('company.settings.embed.update') }}" class="space-y-6 border-t border-[#E5E5EA] pt-6">
                    @csrf
                    @method('PATCH')

                    <div>
                        <label class="inline-flex min-h-11 items-center gap-3">
                            <input
                                id="embed_enabled"
                                name="embed_enabled"
                                type="checkbox"
                                value="1"
                                class="rounded border-[#dde2da] text-[var(--tenant-primary)] shadow-sm focus:ring-[var(--tenant-primary)]"
                                @checked(old('embed_enabled', $company->embed_enabled))
                            >
                            <span class="text-sm font-medium text-[#18201d]">Formulier aanzetten</span>
                        </label>
                        <x-input-error :messages="$errors->get('embed_enabled')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="embed_daily_limit" value="Maximaal aantal opnames per dag" />
                        <x-text-input
                            id="embed_daily_limit"
                            name="embed_daily_limit"
                            class="mt-1 block w-full sm:max-w-40"
                            type="number"
                            min="1"
                            max="500"
                            :value="old('embed_daily_limit', $company->embed_daily_limit ?? \App\Models\Company::DEFAULT_EMBED_DAILY_LIMIT)"
                            required
                        />
                        <p class="mt-2 text-sm text-[#5e6862]">Standaard 20. Beschermt tegen misbruik vanaf je website.</p>
                        <x-input-error :messages="$errors->get('embed_daily_limit')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="embed_allowed_origins" value="Toegestane websites" />
                        <textarea
                            id="embed_allowed_origins"
                            name="embed_allowed_origins"
                            rows="4"
                            class="mt-1 block w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                            placeholder="www.jouwsite.nl&#10;jouwsite.nl"
                        >{{ old('embed_allowed_origins', implode("\n", $company->embedAllowedOrigins())) }}</textarea>
                        <p class="mt-2 text-sm text-[#5e6862]">
                            Eén websitenaam per regel, zonder https:// of pad. Zet www.jouwsite.nl en jouwsite.nl apart als beide nodig zijn. Sterretjes (*) mag niet.
                        </p>
                        <x-input-error :messages="$errors->get('embed_allowed_origins')" class="mt-2" />
                        @foreach ($errors->getMessages() as $key => $messages)
                            @if (str_starts_with($key, 'embed_allowed_origins.'))
                                <x-input-error :messages="$messages" class="mt-2" />
                            @endif
                        @endforeach
                    </div>

                    <div class="flex items-center justify-end">
                        <x-primary-button>Websiteformulier opslaan</x-primary-button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
