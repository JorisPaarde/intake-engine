@php
    /** @var \App\Domains\Intake\Models\PipeRouteSession|null $session */
    $session = $intake->pipeRouteSessions->first();
@endphp

<section class="border-t border-gray-100 pt-4">
    <div class="flex items-center justify-between">
        <h3 class="text-base font-semibold text-gray-800">Leidingroute (begeleid)</h3>
        @if ($session)
            <span @class([
                'rounded-full px-2 py-0.5 text-xs font-medium',
                'bg-green-100 text-green-800' => $session->status === \App\Enums\PipeRouteStatus::Approved,
                'bg-red-100 text-red-800' => $session->status === \App\Enums\PipeRouteStatus::Rejected,
                'bg-amber-100 text-amber-800' => $session->status === \App\Enums\PipeRouteStatus::Proposed,
                'bg-gray-100 text-gray-600' => $session->status === \App\Enums\PipeRouteStatus::Collecting,
            ])>{{ $session->status->label() }}</span>
        @endif
    </div>

    @if (! $session)
        <p class="mt-2 text-sm text-gray-500">Nog geen begeleide leidingroute vastgelegd voor deze opname.</p>
    @else
        <p class="mt-1 text-xs text-gray-500">
            AI levert een voorzet op basis van de foto's; u beoordeelt de uiteindelijke route zelf.
            @if ($session->confidence !== null)
                Zekerheid AI: {{ number_format($session->confidence * 100, 0) }}%.
            @endif
        </p>

        @if (! empty($session->proposed_route))
            <div class="mt-3">
                <h4 class="text-sm font-medium text-gray-700">Voorgestelde route</h4>
                <ol class="mt-1 list-decimal space-y-0.5 pl-5 text-sm text-gray-800">
                    @foreach ($session->proposed_route as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        @if (! empty($session->alternative_route))
            <div class="mt-3">
                <h4 class="text-sm font-medium text-gray-700">Alternatieve route</h4>
                <ol class="mt-1 list-decimal space-y-0.5 pl-5 text-sm text-gray-600">
                    @foreach ($session->alternative_route as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            @if (! empty($session->uncertainties))
                <div>
                    <h4 class="text-sm font-medium text-amber-700">Onzekerheden</h4>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-gray-700">
                        @foreach ($session->uncertainties as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (! empty($session->missing_checks))
                <div>
                    <h4 class="text-sm font-medium text-gray-700">Ontbrekende controles</h4>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-gray-700">
                        @foreach ($session->missing_checks as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        @if ($session->next_photo_instruction)
            <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
                Nog nodig: {{ $session->next_photo_instruction }}
            </p>
        @endif

        @if ($session->segments->isNotEmpty())
            <div class="mt-4">
                <h4 class="text-sm font-medium text-gray-700">Ondersteunende foto's ({{ $session->segments->count() }})</h4>
                <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ($session->segments as $segment)
                        <div class="rounded border border-gray-200 p-2 text-xs">
                            @if ($segment->upload)
                                <a href="{{ route('installer.uploads.show', [$intake, $segment->upload]) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('installer.uploads.show', [$intake, $segment->upload]) }}" alt="Routesegment {{ $segment->sequence }}" class="mb-1 h-24 w-full rounded object-cover">
                                </a>
                            @endif
                            <div class="font-medium text-gray-800">{{ $segment->sequence }}. {{ $segment->label ?? 'segment' }}</div>
                            <div class="text-gray-500">
                                @if ($segment->photo_usable === false)
                                    <span class="text-red-600">foto onbruikbaar</span>
                                @elseif ($segment->route_possible)
                                    route zichtbaar
                                @else
                                    geen route zichtbaar
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($session->status === \App\Enums\PipeRouteStatus::Proposed)
            <div class="mt-4 flex gap-2 border-t border-gray-100 pt-3">
                <form method="POST" action="{{ route('intakes.pipe-route.review', [$intake, $session]) }}">
                    @csrf
                    <input type="hidden" name="decision" value="approve">
                    <x-primary-button type="submit">Route goedkeuren</x-primary-button>
                </form>
                <form method="POST" action="{{ route('intakes.pipe-route.review', [$intake, $session]) }}">
                    @csrf
                    <input type="hidden" name="decision" value="reject">
                    <x-secondary-button type="submit">Afkeuren</x-secondary-button>
                </form>
            </div>
        @elseif ($session->status === \App\Enums\PipeRouteStatus::Approved && $session->approver)
            <p class="mt-3 text-xs text-gray-500">Goedgekeurd door {{ $session->approver->name }} op {{ $session->approved_at?->format('d-m-Y H:i') }}.</p>
        @endif
    @endif
</section>
