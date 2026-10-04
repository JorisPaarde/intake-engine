<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Clients\HeuristicAiClient;
use App\Domains\AI\Clients\NullAiClient;
use App\Domains\AI\Clients\OpenAiClient;
use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\Services\AiTraceRequestIdResolver;
use App\Domains\Intake\Models\Intake;
use App\Policies\IntakePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiClientInterface::class, function (): AiClientInterface {
            return match ((string) config('ai.provider', 'null')) {
                'fake' => new FakeAiClient,
                'heuristic' => new HeuristicAiClient,
                'openai' => app(OpenAiClient::class),
                default => new NullAiClient,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // .user.ini = web only. Hosting CLI is already 256M; this is a minimal vangnet
        // for unlimited (-1) or too-low defaults. Never lower a higher intentional
        // limit (e.g. phpstan --memory-limit=1G) (BL-141).
        if ($this->app->runningInConsole()) {
            $this->applyCliMemoryLimit();
        }

        Gate::policy(Intake::class, IntakePolicy::class);

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $jobId = (string) $event->job->getJobId();
            if ($jobId === '') {
                $jobId = (string) $event->job->uuid();
            }
            if ($jobId !== '') {
                app(AiTraceRequestIdResolver::class)->rememberJobId($jobId);
            }
        });

        RateLimiter::for('customer-intake', function (Request $request) {
            return Limit::perMinute(60)->by((string) $request->ip());
        });

        RateLimiter::for('demo-start', function (Request $request) {
            $perHour = max(1, (int) config('intake.demo.throttle_per_hour', 5));

            return Limit::perHour($perHour)->by((string) $request->ip());
        });

        RateLimiter::for('product-interest', function (Request $request) {
            $perHour = max(1, (int) config('intake.interest.throttle_per_hour', 5));

            return Limit::perHour($perHour)
                ->by(hash('sha256', (string) $request->ip()));
        });

        RateLimiter::for('dev-ai-input-test', function (Request $request) {
            $perMinute = max(1, (int) config('devadmin.ai_input_test.throttle_per_minute', 10));
            $userId = $request->user()?->id;

            return Limit::perMinute($perMinute)->by(
                $userId !== null
                    ? 'user:'.$userId
                    : 'ip:'.hash('sha256', (string) $request->ip()),
            );
        });
    }

    /**
     * Minimal CLI vangnet: only when unlimited or below the floor; never shrink higher.
     */
    private function applyCliMemoryLimit(): void
    {
        $desired = (string) config('intake.php.cli_memory_limit', '256M');
        if ($desired === '' || $desired === '-1') {
            return;
        }

        $current = (string) ini_get('memory_limit');
        $desiredBytes = $this->memoryLimitToBytes($desired);
        $currentBytes = $this->memoryLimitToBytes($current);

        if ($desiredBytes <= 0) {
            return;
        }

        // Hosting CLI is already 256M; only act on -1 or a too-low default.
        if ($current === '-1' || ($currentBytes > 0 && $currentBytes < $desiredBytes)) {
            ini_set('memory_limit', $desired);
        }
    }

    private function memoryLimitToBytes(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '-1') {
            return -1;
        }

        if (! preg_match('/^(\d+)([KMG])?$/i', $trimmed, $matches)) {
            return 0;
        }

        $bytes = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'K' => $bytes * 1024,
            'M' => $bytes * 1024 * 1024,
            'G' => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }
}
