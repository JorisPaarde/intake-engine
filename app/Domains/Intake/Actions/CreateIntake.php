<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\IntakeAccessTokenGenerator;
use App\Enums\IntakeStatus;
use App\Enums\TemplateVersionStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class CreateIntake
{
    public function __construct(
        private readonly IntakeAccessTokenGenerator $tokenGenerator,
    ) {}

    /**
     * @param  array{
     *     customer_name: string,
     *     customer_email: string,
     *     customer_phone?: string|null,
     *     address_line: string,
     *     address_postal_code?: string|null,
     *     address_city?: string|null,
     *     internal_note?: string|null,
     *     template_key?: string,
     *     is_demo?: bool,
     *     token_ttl_hours?: int
     * }  $data
     */
    public function handle(User $creator, array $data): Intake
    {
        $templateKey = $data['template_key'] ?? 'airco';
        $isDemo = (bool) ($data['is_demo'] ?? false);

        $version = $this->resolvePublishedVersion($templateKey);

        return DB::transaction(function () use ($creator, $data, $version, $isDemo): Intake {
            $ttlHours = isset($data['token_ttl_hours']) ? (int) $data['token_ttl_hours'] : null;
            $expiresAt = $ttlHours !== null
                ? now()->addHours(max(1, $ttlHours))
                : now()->addDays((int) config('intake.token_ttl_days', 60));

            $intake = Intake::query()->create([
                'intake_template_version_id' => $version->id,
                'created_by' => $creator->id,
                'status' => IntakeStatus::Sent,
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'address_line' => $data['address_line'],
                'address_postal_code' => $data['address_postal_code'] ?? null,
                'address_city' => $data['address_city'] ?? null,
                'access_token' => $this->tokenGenerator->generate(),
                'token_expires_at' => $expiresAt,
                'internal_note' => $data['internal_note'] ?? null,
                'progress_percent' => 0,
                'is_demo' => $isDemo,
            ]);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $creator->id,
                'event' => 'intake_created',
                'properties' => [
                    'template_key' => $version->template->key,
                    'template_version' => $version->version,
                ],
                'created_at' => now(),
            ]);

            return $intake->fresh(['templateVersion.template']) ?? $intake;
        });
    }

    private function resolvePublishedVersion(string $templateKey): IntakeTemplateVersion
    {
        $template = IntakeTemplate::query()
            ->where('key', $templateKey)
            ->where('is_active', true)
            ->first();

        if ($template === null) {
            throw ValidationException::withMessages([
                'template_key' => 'Er is geen actieve intaketemplate beschikbaar.',
            ]);
        }

        $version = IntakeTemplateVersion::query()
            ->where('intake_template_id', $template->id)
            ->where('status', TemplateVersionStatus::Published)
            ->orderByDesc('version')
            ->first();

        if ($version === null) {
            throw new RuntimeException("Template [{$templateKey}] has no published version.");
        }

        $version->setRelation('template', $template);

        return $version;
    }
}
