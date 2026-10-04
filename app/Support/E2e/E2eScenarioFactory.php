<?php

declare(strict_types=1);

namespace App\Support\E2e;

use App\Domains\AI\Support\E2eAiScenario;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds deterministic intakes for Playwright customer-flow scenarios.
 */
final class E2eScenarioFactory
{
    public function __construct(
        private readonly SaveIntakeAnswer $saveAnswer,
        private readonly DossierManager $dossierManager,
        private readonly CreateCustomerContributionRequest $createContribution,
        private readonly StoreIntakeUpload $storeUpload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(string $scenario): array
    {
        return match ($scenario) {
            'wizard-happy-path', 'empty-living-room' => $this->emptyLivingRoom(),
            'fusebox-upload' => $this->fuseboxUploadReady(),
            'follow-up-mismatch' => $this->followUpMismatch(),
            'progress-empty' => $this->progressEmpty(),
            'drain-facade' => $this->drainAndFacade(),
            'feedback' => $this->feedbackReady(),
            'room-name-autosave' => $this->roomNameAutosave(),
            default => throw new \InvalidArgumentException('Unknown E2E scenario: '.$scenario),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLivingRoom(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Woonkamer',
            'customer_email' => 'e2e-woonkamer@example.com',
            'is_demo' => true,
            'request_reason' => 'Airco in de woonkamer, koelen en verwarmen, vrijstaande woning.',
        ]);

        $this->seedLivingRoomKnown($intake);

        return $this->payload($intake, 'empty-living-room');
    }

    /**
     * @return array<string, mixed>
     */
    private function fuseboxUploadReady(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Meterkast',
            'customer_email' => 'e2e-meterkast@example.com',
        ]);

        $this->seedLivingRoomKnown($intake);
        $this->seedThroughFusebox($intake);

        return $this->payload($intake, 'fusebox-upload', [
            'target_question_key' => 'fusebox_photo',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpMismatch(): array
    {
        E2eAiScenario::set(E2eAiScenario::WRONG_SUBJECT);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Aanvulling',
            'customer_email' => 'e2e-aanvulling@example.com',
            'status' => IntakeStatus::InProgress,
        ]);

        $this->seedLivingRoomKnown($intake);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $user = User::query()->findOrFail($intake->created_by);
        $round = $this->createContribution->handle($intake->fresh() ?? $intake, $user, [[
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Maak een duidelijke foto van de meterkast voor de stroomtoevoer.',
            'decision_area_key' => 'power',
        ], [
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Maak een scherpe, goed belichte foto van de meterkast (niet te klein).',
            'decision_area_key' => 'power',
        ]]);

        $intake->refresh();

        return $this->payload($intake, 'follow-up-mismatch', [
            'follow_up_round_id' => $round->id,
            'follow_up_item_ids' => $round->items()->pluck('id')->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function progressEmpty(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Voortgang',
            'customer_email' => 'e2e-voortgang@example.com',
        ]);

        // Prefill rooms so known-summary exists, but leave customer work open.
        $this->seedLivingRoomKnown($intake);

        return $this->payload($intake, 'progress-empty');
    }

    /**
     * @return array<string, mixed>
     */
    private function drainAndFacade(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Afvoer',
            'customer_email' => 'e2e-afvoer@example.com',
        ]);

        $this->seedLivingRoomKnown($intake);
        $this->seedThroughFusebox($intake);

        // Start on the drain questions — do not prefill drain_location (test answers it).
        $intake->update([
            'current_section_key' => 'condensate',
            'current_question_key' => 'drain_location',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'drain-facade', [
            'target_question_key' => 'drain_location',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function feedbackReady(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Feedback',
            'customer_email' => 'e2e-feedback@example.com',
        ]);

        $this->seedLivingRoomKnown($intake);

        return $this->payload($intake, 'feedback', [
            'target_question_key' => 'room_photos',
        ]);
    }

    /**
     * Two bedrooms at room_name: blur/autosave must keep the question sticky (BL-140).
     * preferred_indoor is prefilled so Volgende lands on wall_outlet_photo.
     *
     * @return array<string, mixed>
     */
    private function roomNameAutosave(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Kamernaam',
            'customer_email' => 'e2e-kamernaam@example.com',
            'request_reason' => 'Twee slaapkamers koelen',
        ]);

        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::REQUEST_TEXT);
        $this->saveAnswer->handle($intake, 'cooling_heating', null, ['value' => 'both'], PrefillSources::REQUEST_TEXT);
        $this->saveAnswer->handle($intake, 'building_type', null, ['value' => 'detached'], PrefillSources::REQUEST_TEXT);
        $this->saveAnswer->handle($intake, 'ownership', null, ['value' => 'owned'], PrefillSources::REQUEST_TEXT);

        foreach (['room-1', 'room-2'] as $instance) {
            foreach ([
                ['room_type', ['value' => 'bedroom'], PrefillSources::AI_TEXT],
                ['room_size_indication', ['value' => 'medium'], PrefillSources::AI_TEXT],
                ['room_length_m', ['number' => 4.0], PrefillSources::AI_TEXT],
                ['room_width_m', ['number' => 3.0], PrefillSources::AI_TEXT],
                ['room_area_m2', ['number' => 12.0], PrefillSources::AI_TEXT],
                ['ceiling_height_m', ['number' => 2.5], PrefillSources::AI_TEXT],
                ['sun_exposure', ['value' => 'medium'], PrefillSources::AI_TEXT],
                ['glass_amount', ['value' => 'average'], PrefillSources::AI_TEXT],
                ['glazing_type', ['value' => 'double'], PrefillSources::AI_TEXT],
                ['floor_level', ['value' => '1'], PrefillSources::AI_TEXT],
                ['room_outlet_status', ['value' => 'needs_photo'], PrefillSources::AI_PHOTO],
                ['preferred_indoor_location', ['text' => 'Boven de deur'], PrefillSources::AI_TEXT],
            ] as [$key, $value, $source]) {
                $this->saveAnswer->handle($intake, $key, $instance, $value, $source);
            }

            foreach (['room_photos', 'indoor_unit_position_photo'] as $photoKey) {
                $upload = $this->storeUpload->handle(
                    $intake,
                    $photoKey,
                    $instance,
                    $this->fixtureUpload('woonkamer-1440.jpg'),
                );
                $upload->updateQuietly([
                    'usability_verdict' => PhotoUsabilityVerdict::Ok,
                    'assessment_status' => PhotoAssessmentStatus::Assessed,
                    'content_assessment' => [
                        'status' => 'ok',
                        'expected_subject' => 'room',
                        'detected_subject' => 'room',
                        'customer_message' => null,
                    ],
                ]);
            }
        }

        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'rooms',
            'current_question_key' => 'room_name',
            'current_section_instance_key' => 'room-1',
        ]);

        return $this->payload($intake, 'room-name-autosave', [
            'target_question_key' => 'room_name',
        ]);
    }

    /**
     * Seed-time photo from a git-tracked fixture (not tests/e2e/fixtures/*.jpg — those are generated).
     */
    private function fixtureUpload(string $name): UploadedFile
    {
        $path = base_path('tests/fixtures/klanttest-20261002/'.$name);

        if (! is_file($path)) {
            throw new \RuntimeException('E2E fixture ontbreekt: '.$name);
        }

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function baseIntake(array $overrides = []): Intake
    {
        $company = Company::query()->firstOrCreate(
            ['slug' => 'e2e-installateur'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'E2E Installateur',
                'primary_color' => Company::DEFAULT_PRIMARY,
                'accent_color' => Company::DEFAULT_ACCENT,
                'on_primary_color' => Company::DEFAULT_ON_PRIMARY,
            ],
        );

        $user = User::query()->updateOrCreate(
            ['email' => 'e2e-installateur@example.com'],
            [
                'company_id' => $company->id,
                'name' => 'E2E Installateur',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

        $requestReason = $overrides['request_reason'] ?? null;
        unset($overrides['request_reason']);

        $intake = Intake::factory()->create(array_merge([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'intake_template_version_id' => $version->id,
            'status' => IntakeStatus::Sent,
            'customer_name' => 'E2E Klant',
            'customer_email' => 'e2e-klant@example.com',
            'address_line' => 'Teststraat 1',
            'address_city' => 'Haarlem',
            'address_postal_code' => '2037GR',
            'address_house_number' => '273',
            'access_token' => Str::random(64),
            'token_expires_at' => now()->addDays(14),
            'is_demo' => true,
        ], $overrides));

        if (is_string($requestReason) && $requestReason !== '') {
            $this->saveAnswer->handle($intake, 'request_reason', null, ['text' => $requestReason], PrefillSources::REQUEST_TEXT);
        }

        return $intake->fresh() ?? $intake;
    }

    private function seedLivingRoomKnown(Intake $intake): void
    {
        $pairs = [
            ['request_reason', null, ['text' => 'Airco in de woonkamer voor koelen en verwarmen.']],
            ['indoor_unit_count', null, ['number' => 1]],
            ['cooling_heating', null, ['value' => 'both']],
            ['building_type', null, ['value' => 'detached']],
            ['room_type', 'room-1', ['value' => 'living_room']],
            ['room_name', 'room-1', ['text' => 'Woonkamer']],
            ['ownership', null, ['value' => 'owned']],
        ];

        foreach ($pairs as [$key, $instance, $value]) {
            $this->saveAnswer->handle($intake, $key, $instance, $value, PrefillSources::REQUEST_TEXT);
        }

        $this->dossierManager->initialize($intake->fresh() ?? $intake);
    }

    private function seedThroughFusebox(Intake $intake): void
    {
        $photoPrefills = [
            ['room_size_indication', 'room-1', ['value' => 'large']],
            ['sun_exposure', 'room-1', ['value' => 'medium']],
            ['glass_amount', 'room-1', ['value' => 'average']],
            ['glazing_type', 'room-1', ['value' => 'double']],
            ['room_outlet_status', 'room-1', ['value' => 'present']],
            ['room_extra_overview_needed', 'room-1', ['value' => 'complete']],
            ['room_length_m', 'room-1', ['number' => 5.0]],
            ['room_width_m', 'room-1', ['number' => 4.0]],
            ['room_area_m2', 'room-1', ['number' => 20.0]],
            ['ceiling_height_m', 'room-1', ['number' => 2.6]],
            ['floor_level', 'room-1', ['value' => '0']],
            ['preferred_indoor_location', 'room-1', ['text' => 'Aan de buitenmuur boven de bank']],
            ['outdoor_location', null, ['value' => 'garden']],
            ['outdoor_accessibility', null, ['value' => 'easy_ground']],
            ['noise_sensitive', null, ['bool' => false]],
            ['build_year', null, ['number' => 1998]],
            ['insulation_indication', null, ['value' => 'average']],
            ['floor_insulation', null, ['value' => 'unknown']],
            ['crawl_space_present', null, ['bool' => false]],
            ['pipe_visibility', null, ['value' => 'unknown']],
        ];

        foreach ($photoPrefills as [$key, $instance, $value]) {
            $this->saveAnswer->handle($intake, $key, $instance, $value, PrefillSources::AI_TEXT);
        }

        // Skip photo questions that would block reaching fusebox by marking them answered
        // via empty optional skip where the template allows — otherwise leave for the test.
        $intake->update([
            'current_section_key' => 'electrical',
            'current_question_key' => 'fusebox_photo',
            'current_section_instance_key' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(Intake $intake, string $scenario, array $extra = []): array
    {
        $fresh = $intake->fresh() ?? $intake;

        return array_merge([
            'scenario' => $scenario,
            'intake_id' => $fresh->id,
            'access_token' => $fresh->access_token,
            'customer_url' => url('/o/'.$fresh->access_token),
            'ai_scenario' => E2eAiScenario::get(),
        ], $extra);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function uploadSummaries(Intake $intake): array
    {
        return $intake->uploads()
            ->orderBy('id')
            ->get()
            ->map(function (IntakeUpload $upload): array {
                $width = null;
                $height = null;
                $disk = $upload->disk ?: (string) config('filesystems.media', 'local');
                $path = $upload->path;
                if ($path !== '' && Storage::disk($disk)->exists($path)) {
                    $bytes = Storage::disk($disk)->get($path);
                    if (is_string($bytes) && $bytes !== '') {
                        $info = @getimagesizefromstring($bytes);
                        if (is_array($info)) {
                            $width = (int) $info[0];
                            $height = (int) $info[1];
                        }
                    }
                }

                return [
                    'id' => $upload->id,
                    'question_key' => $upload->question_key,
                    'section_instance_key' => $upload->section_instance_key,
                    'original_filename' => $upload->original_filename,
                    'size_bytes' => $upload->size_bytes,
                    'width' => $width,
                    'height' => $height,
                    'original_width' => is_array($upload->processing_timings)
                        ? ($upload->processing_timings['original_width'] ?? null)
                        : null,
                    'original_height' => is_array($upload->processing_timings)
                        ? ($upload->processing_timings['original_height'] ?? null)
                        : null,
                    'assessment_status' => $upload->assessment_status?->value,
                    'usability_verdict' => $upload->usability_verdict?->value,
                    'checksum' => $upload->checksum,
                ];
            })
            ->values()
            ->all();
    }
}
