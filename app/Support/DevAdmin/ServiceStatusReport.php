<?php

declare(strict_types=1);

namespace App\Support\DevAdmin;

use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Services\EpOnlineService;
use App\Domains\Intake\Services\KadasterBagService;
use App\Domains\Intake\Services\PdokAddressService;
use App\Domains\Intake\Services\PdokAerialImageService;
use App\Domains\Intake\Services\ThreeDBagService;
use App\Enums\AiRunStatus;
use Illuminate\Support\Carbon;

/**
 * Passieve statusweergave van de externe diensten: leidt "werkt het?" af uit de
 * config-vlaggen en de laatst opgeslagen resultaten (externe feiten + AI-runs).
 * Doet bewust géén live calls — zie het plan / de dev-admin-keuze.
 */
final class ServiceStatusReport
{
    /**
     * @return list<array<string, mixed>>
     */
    public function services(): array
    {
        $facts = $this->latestFactsBySource();
        $ai = $this->latestAiRun();
        $aiFailures = AiRun::query()->where('status', AiRunStatus::Failed)->count();

        $provider = (string) config('ai.provider', 'null');
        $mailer = (string) config('mail.default', 'log');
        $slackToken = trim((string) config('services.slack.notifications.bot_user_oauth_token', ''));

        return [
            $this->geoRow(
                key: 'pdok',
                label: 'PDOK Locatieserver + BAG',
                enabled: (bool) config('services.pdok.enabled', true),
                baseUrl: (string) config('services.pdok.search_base_url'),
                timeout: (int) config('services.pdok.timeout_seconds', 5),
                source: PdokAddressService::sourceName(),
                facts: $facts,
            ),
            $this->geoRow(
                key: 'pdok_aerial',
                label: 'PDOK Luchtfoto (WMS)',
                enabled: (bool) config('services.pdok.aerial_enabled', true),
                baseUrl: (string) config('services.pdok.aerial_wms_url'),
                timeout: (int) config('services.pdok.aerial_timeout_seconds', 4),
                source: PdokAerialImageService::sourceName(),
                facts: $facts,
            ),
            $this->geoRow(
                key: 'kadaster_bag',
                label: 'Kadaster BAG (Individuele Bevragingen)',
                enabled: (bool) config('services.bag_api.enabled', false),
                baseUrl: (string) config('services.bag_api.base_url'),
                timeout: (int) config('services.bag_api.timeout_seconds', 5),
                source: KadasterBagService::sourceName(),
                facts: $facts,
                requiresKey: true,
                hasKey: trim((string) config('services.bag_api.key', '')) !== '',
            ),
            $this->geoRow(
                key: 'ep_online',
                label: 'EP-Online (RVO) energielabels',
                enabled: (bool) config('services.ep_online.enabled', false),
                baseUrl: (string) config('services.ep_online.base_url'),
                timeout: (int) config('services.ep_online.timeout_seconds', 5),
                source: EpOnlineService::sourceName(),
                facts: $facts,
                requiresKey: true,
                hasKey: trim((string) config('services.ep_online.key', '')) !== '',
            ),
            $this->geoRow(
                key: 'threedbag',
                label: '3DBAG (TU Delft) pandgeometrie',
                enabled: (bool) config('services.threedbag.enabled', true),
                baseUrl: (string) config('services.threedbag.base_url'),
                timeout: (int) config('services.threedbag.timeout_seconds', 5),
                source: ThreeDBagService::sourceName(),
                facts: $facts,
            ),
            [
                'key' => 'ai',
                'label' => 'AI-provider ('.$provider.')',
                'category' => 'ai',
                'enabled' => $provider !== 'null',
                'requires_key' => $provider === 'openai',
                'configured' => $provider === 'openai'
                    ? trim((string) config('ai.api_key', '')) !== ''
                    : null,
                'base_url' => $provider === 'openai' ? (string) config('ai.base_url') : null,
                'timeout' => (int) config('ai.timeout_seconds', 20),
                'detail' => $ai !== null ? 'model: '.((string) ($ai->model ?? '—')) : null,
                'last_at' => $ai?->started_at,
                'last_status' => $ai?->status->value,
                'last_error' => $ai?->error_message,
                'failures' => $aiFailures,
            ],
            [
                'key' => 'mail',
                'label' => 'Mail ('.$mailer.')',
                'category' => 'mail',
                'enabled' => true,
                'requires_key' => false,
                'configured' => null,
                'base_url' => null,
                'timeout' => null,
                'detail' => $mailer === 'log'
                    ? 'MAIL_MAILER=log — mails gaan naar het logbestand, niet naar de klant.'
                    : 'Actieve mailer; verzendresultaat staat niet in de database.',
                'last_at' => null,
                'last_status' => null,
                'last_error' => null,
                'failures' => 0,
            ],
            [
                'key' => 'slack',
                'label' => 'Slack-notificaties',
                'category' => 'slack',
                'enabled' => $slackToken !== '',
                'requires_key' => true,
                'configured' => $slackToken !== '',
                'base_url' => null,
                'timeout' => null,
                'detail' => 'kanaal: '.((string) config('services.slack.notifications.channel', '—')),
                'last_at' => null,
                'last_status' => null,
                'last_error' => null,
                'failures' => 0,
            ],
        ];
    }

    /**
     * @param  array<string, array{last_at: string|null, cnt: int}>  $facts
     * @return array<string, mixed>
     */
    private function geoRow(
        string $key,
        string $label,
        bool $enabled,
        string $baseUrl,
        int $timeout,
        string $source,
        array $facts,
        bool $requiresKey = false,
        bool $hasKey = true,
    ): array {
        $row = $facts[$source] ?? null;
        $lastAt = ($row['last_at'] ?? null) !== null ? Carbon::parse($row['last_at']) : null;

        return [
            'key' => $key,
            'label' => $label,
            'category' => 'geo',
            'enabled' => $enabled,
            'requires_key' => $requiresKey,
            'configured' => $requiresKey ? $hasKey : null,
            'base_url' => $baseUrl,
            'timeout' => $timeout,
            'detail' => 'bron: '.$source,
            'last_at' => $lastAt,
            'last_status' => $lastAt !== null ? AiRunStatus::Succeeded->value : null,
            'last_error' => null,
            'failures' => 0,
            'fact_count' => (int) ($row['cnt'] ?? 0),
        ];
    }

    /**
     * @return array<string, array{last_at: string|null, cnt: int}>
     */
    private function latestFactsBySource(): array
    {
        return IntakeExternalFact::query()
            ->selectRaw('source, MAX(captured_at) as last_at, COUNT(*) as cnt')
            ->groupBy('source')
            ->toBase()
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->source => [
                    'last_at' => $row->last_at !== null ? (string) $row->last_at : null,
                    'cnt' => (int) $row->cnt,
                ],
            ])
            ->all();
    }

    private function latestAiRun(): ?AiRun
    {
        return AiRun::query()->latest('started_at')->first();
    }
}
