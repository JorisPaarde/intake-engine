<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Clients\HeuristicAiClient;
use App\Domains\AI\Clients\NullAiClient;
use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\Intake\Models\Intake;
use App\Policies\IntakePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
                default => new NullAiClient,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Intake::class, IntakePolicy::class);

        RateLimiter::for('customer-intake', function (Request $request) {
            return Limit::perMinute(60)->by((string) $request->ip());
        });
    }
}
