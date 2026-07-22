<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DevActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'event' => ['nullable', 'string'],
            'actor_type' => ['nullable', 'string'],
        ]);

        $events = IntakeActivityEvent::query()
            ->with('intake')
            ->when(
                trim((string) ($validated['event'] ?? '')) !== '',
                fn ($query) => $query->where('event', $validated['event']),
            )
            ->when(
                trim((string) ($validated['actor_type'] ?? '')) !== '',
                fn ($query) => $query->where('actor_type', $validated['actor_type']),
            )
            ->latest('created_at')
            ->paginate(40)
            ->withQueryString();

        return view('dev.activity', [
            'events' => $events,
            'eventNames' => IntakeActivityEvent::query()->distinct()->orderBy('event')->pluck('event'),
            'actorTypes' => IntakeActivityEvent::query()->distinct()->orderBy('actor_type')->pluck('actor_type'),
            'filters' => $validated,
        ]);
    }
}
