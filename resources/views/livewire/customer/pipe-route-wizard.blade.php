<div class="mx-auto max-w-xl space-y-6 px-4 py-8">
    <div>
        <h1 class="text-xl font-semibold text-gray-900">Leidingroute vastleggen</h1>
        <p class="mt-1 text-sm text-gray-600">
            Maak stap voor stap foto's van de route tussen de binnenunit en de plek van de buitenunit.
            We laten per foto weten of er nog iets ontbreekt. De installateur beoordeelt de route daarna zelf.
        </p>
    </div>

    @if ($session->next_photo_instruction)
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-sm font-medium text-amber-900">Volgende foto</p>
            <p class="text-sm text-amber-900">{{ $session->next_photo_instruction }}</p>
        </div>
    @endif

    {{-- Reeds vastgelegde segmenten --}}
    @if ($session->segments->isNotEmpty())
        <div class="space-y-2">
            <h2 class="text-sm font-semibold text-gray-800">Gemaakte foto's ({{ $session->segments->count() }})</h2>
            <ul class="space-y-2">
                @foreach ($session->segments as $segment)
                    <li class="flex items-center gap-3 rounded-md border border-gray-200 p-2">
                        @if ($segment->upload)
                            <img src="{{ route('customer.uploads.show', [$token, $segment->upload]) }}" alt="Foto {{ $segment->sequence }}" class="h-12 w-12 rounded object-cover">
                        @endif
                        <div class="min-w-0 text-sm">
                            <div class="font-medium text-gray-800">{{ $segment->sequence }}. {{ $roles[$segment->label] ?? $segment->label }}</div>
                            <div class="text-xs text-gray-500">
                                @if ($segment->photo_usable === false)
                                    <span class="text-red-600">Foto onduidelijk — maak deze opnieuw.</span>
                                @elseif ($segment->photo_usable === true)
                                    {{ $segment->route_possible ? 'Route zichtbaar op deze foto.' : 'Bruikbaar, maar route nog niet zichtbaar.' }}
                                @else
                                    Foto opgeslagen.
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Nieuwe foto toevoegen --}}
    @if ($session->status !== \App\Enums\PipeRouteStatus::Approved)
        <div class="space-y-3 rounded-md border border-gray-200 p-4">
            <div>
                <label for="label" class="block text-sm font-medium text-gray-700">Wat laat deze foto zien?</label>
                <select id="label" wire:model="label" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                    @foreach ($roles as $value => $text)
                        <option value="{{ $value }}">{{ $text }}</option>
                    @endforeach
                </select>
                @error('label') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="photo" class="block text-sm font-medium text-gray-700">Foto</label>
                <input id="photo" type="file" accept="image/*" wire:model="photo" class="mt-1 block w-full text-sm">
                <div wire:loading wire:target="photo" class="mt-1 text-xs text-gray-500">Foto laden…</div>
                @error('photo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                @if ($photo)
                    <img src="{{ $photo->temporaryUrl() }}" alt="Voorbeeld" class="mt-2 h-32 rounded object-cover">
                @endif
            </div>

            <button
                type="button"
                wire:click="addPhoto"
                wire:loading.attr="disabled"
                wire:target="addPhoto,photo"
                class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="addPhoto">Foto toevoegen</span>
                <span wire:loading wire:target="addPhoto">Beoordelen…</span>
            </button>

            @unless ($aiEnabled)
                <p class="text-xs text-gray-400">Automatische beoordeling staat op deze omgeving uit; je foto's worden wel bewaard.</p>
            @endunless
        </div>

        @if ($session->segments->isNotEmpty())
            <button
                type="button"
                wire:click="synthesize"
                wire:loading.attr="disabled"
                wire:target="synthesize"
                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="synthesize">Route samenvatten</span>
                <span wire:loading wire:target="synthesize">Samenvatten…</span>
            </button>
        @endif
    @endif

    {{-- Samengevatte route --}}
    @if (! empty($session->proposed_route))
        <div class="space-y-3 rounded-md border border-green-200 bg-green-50 p-4">
            <h2 class="text-sm font-semibold text-green-900">Voorgestelde route</h2>
            <ol class="list-decimal space-y-0.5 pl-5 text-sm text-gray-800">
                @foreach ($session->proposed_route as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>

            @if (! empty($session->missing_checks))
                <div>
                    <p class="text-sm font-medium text-amber-800">Nog handig om vast te leggen</p>
                    <ul class="list-disc space-y-0.5 pl-5 text-sm text-gray-700">
                        @foreach ($session->missing_checks as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="text-xs text-gray-500">De installateur controleert en bevestigt de definitieve route.</p>
        </div>
    @endif
</div>
