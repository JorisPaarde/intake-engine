<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold leading-tight text-[#1D1D1F]">
                Bedrijfsinstellingen
            </h2>
            <p class="text-sm text-[#6E6E73]">Naam, logo en actiekleur voor installateur en klant.</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-xl border border-emerald-200 bg-white px-4 py-3 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('company.settings.update') }}" enctype="multipart/form-data" class="space-y-6 rounded-xl border border-[#D2D2D7] bg-white p-6 shadow-sm">
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
                            <img src="{{ route('company.logo.show', $company) }}" alt="{{ $company->name }}" class="h-14 w-14 rounded-xl border border-[#D2D2D7] object-contain">
                        @else
                            <div class="flex h-14 w-14 items-center justify-center rounded-xl border border-[#D2D2D7] bg-[#F5F5F7] text-sm font-semibold text-[#6E6E73]">
                                {{ mb_strtoupper(mb_substr($company->name, 0, 1)) }}
                            </div>
                        @endif
                        <input id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-[#424245] file:mr-4 file:min-h-11 file:rounded-xl file:border-0 file:bg-[var(--tenant-primary)] file:px-4 file:text-sm file:font-semibold file:text-[var(--tenant-on-primary)]">
                    </div>
                    <p class="mt-2 text-sm text-[#6E6E73]">JPEG, PNG of WebP. Maximaal 2 MB.</p>
                    <x-input-error :messages="$errors->get('logo')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="primary_color" value="Primaire kleur (optioneel)" />
                    <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <input id="primary_color" name="primary_color" type="text" value="{{ old('primary_color', $company->primary_color) }}" placeholder="#0071E3" class="block min-h-11 w-full rounded-xl border-[#D2D2D7] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)] sm:max-w-48">
                        <span class="inline-flex h-11 w-20 rounded-xl border border-[#D2D2D7]" style="background-color: {{ $company->themeTokens()['primary'] }}"></span>
                    </div>
                    <p class="mt-2 text-sm text-[#6E6E73]">Laat leeg om bij een nieuw logo automatisch een veilige kleur te kiezen.</p>
                    <x-input-error :messages="$errors->get('primary_color')" class="mt-2" />
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-[#E5E5EA] pt-6">
                    <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-semibold text-[#424245] hover:text-[#1D1D1F] focus:outline-none focus:ring-2 focus:ring-[var(--tenant-primary)] focus:ring-offset-2">Annuleren</a>
                    <x-primary-button>Opslaan</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
