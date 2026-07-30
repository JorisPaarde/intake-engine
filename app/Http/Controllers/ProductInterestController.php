<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductInterestRequest;
use App\Mail\ProductInterestReceivedMail;
use App\Models\ProductInterest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class ProductInterestController extends Controller
{
    public function __invoke(StoreProductInterestRequest $request): RedirectResponse
    {
        if ($request->filled('website')) {
            return $this->successResponse();
        }

        $data = $request->validated();
        $retentionDays = max(1, (int) config('intake.interest.retention_days', 365));

        $interest = ProductInterest::query()->create([
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'] ?? null,
            'expires_at' => now()->addDays($retentionDays),
        ]);

        $this->queueNotification($interest);

        return $this->successResponse();
    }

    private function queueNotification(ProductInterest $interest): void
    {
        $recipient = trim((string) config('intake.interest.recipient'));

        if (
            $recipient === ''
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || config('mail.default') === 'log'
        ) {
            return;
        }

        try {
            Mail::to($recipient)->queue(new ProductInterestReceivedMail($interest));

            $interest->forceFill([
                'notification_queued_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('Failed to queue product interest notification', [
                'product_interest_id' => $interest->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function successResponse(): RedirectResponse
    {
        return redirect()
            ->to(route('home').'#interesse')
            ->with('interest_submitted', true);
    }
}
