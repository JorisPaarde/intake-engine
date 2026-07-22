<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DevIntakeController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));

        $intakes = Intake::withTrashed()
            ->with('templateVersion.template')
            ->withCount(['answers', 'uploads', 'externalFacts', 'aiRuns', 'activityEvents'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('uuid', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%")
                        ->orWhere('address_line', 'like', "%{$search}%")
                        ->orWhere('address_city', 'like', "%{$search}%");
                });
            })
            ->when(
                IntakeStatus::tryFrom((string) ($validated['status'] ?? '')),
                fn ($query, IntakeStatus $status) => $query->where('status', $status),
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('dev.intakes.index', [
            'intakes' => $intakes,
            'statuses' => IntakeStatus::cases(),
            'filters' => $validated,
        ]);
    }

    public function show(Intake $intake): View
    {
        $intake->loadMissing([
            'templateVersion.template',
            'creator',
            'externalFacts',
            'aiRuns',
            'answers',
            'uploads',
            'attentionPoints',
            'review',
            'notes',
            'activityEvents',
            'followUpRounds.items',
        ]);

        return view('dev.intakes.show', [
            'intake' => $intake,
        ]);
    }
}
