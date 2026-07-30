<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Domains\AI\Models\AiRun;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Models\Company;
use App\Models\User;
use Database\Factories\IntakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $uuid
 * @property IntakeStatus $status
 * @property ContributionMode $workflow_mode
 * @property string $access_token
 * @property bool $customer_access_enabled
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $token_revoked_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $reminder_sent_at
 * @property int|null $address_house_number
 * @property string|null $address_house_number_addition
 * @property int $progress_percent
 * @property bool $is_demo
 * @property array<string, mixed>|null $completeness_snapshot
 */
class Intake extends Model
{
    /** @use HasFactory<IntakeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'intake_template_version_id',
        'company_id',
        'created_by',
        'status',
        'workflow_mode',
        'customer_name',
        'customer_email',
        'customer_phone',
        'address_line',
        'address_postal_code',
        'address_house_number',
        'address_house_number_addition',
        'address_city',
        'access_token',
        'customer_access_enabled',
        'token_expires_at',
        'token_revoked_at',
        'internal_note',
        'current_section_key',
        'current_question_key',
        'current_section_instance_key',
        'progress_percent',
        'is_demo',
        'started_at',
        'completed_at',
        'reviewed_at',
        'reminder_sent_at',
        'completeness_snapshot',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => IntakeStatus::class,
            'workflow_mode' => ContributionMode::class,
            'customer_access_enabled' => 'boolean',
            'token_expires_at' => 'datetime',
            'token_revoked_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'address_house_number' => 'integer',
            'progress_percent' => 'integer',
            'is_demo' => 'boolean',
            'completeness_snapshot' => 'array',
        ];
    }

    public function isAwaitingReview(): bool
    {
        return $this->status === IntakeStatus::Completed && $this->reviewed_at === null;
    }

    protected static function booted(): void
    {
        static::creating(function (Intake $intake): void {
            if (! isset($intake->attributes['uuid'])) {
                $intake->uuid = (string) Str::uuid();
            }

            if (! isset($intake->attributes['company_id']) && isset($intake->attributes['created_by'])) {
                $companyId = User::query()
                    ->whereKey($intake->attributes['created_by'])
                    ->value('company_id');

                if ($companyId !== null) {
                    $intake->company_id = (int) $companyId;
                }
            }
        });
    }

    protected static function newFactory(): IntakeFactory
    {
        return IntakeFactory::new();
    }

    /** @return BelongsTo<IntakeTemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(IntakeTemplateVersion::class, 'intake_template_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<IntakeAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(IntakeAnswer::class);
    }

    /** @return HasMany<IntakeUpload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(IntakeUpload::class);
    }

    /** @return HasMany<IntakeNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(IntakeNote::class);
    }

    /** @return HasMany<IntakeAttentionPoint, $this> */
    public function attentionPoints(): HasMany
    {
        return $this->hasMany(IntakeAttentionPoint::class);
    }

    /** @return HasMany<IntakeExternalFact, $this> */
    public function externalFacts(): HasMany
    {
        return $this->hasMany(IntakeExternalFact::class);
    }

    /** @return HasMany<IntakeFollowUpRound, $this> */
    public function followUpRounds(): HasMany
    {
        return $this->hasMany(IntakeFollowUpRound::class)->orderBy('round_number');
    }

    /** @return HasOne<IntakeReview, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(IntakeReview::class);
    }

    /** @return HasOne<GeneratedReport, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(GeneratedReport::class);
    }

    /** @return HasMany<AiRun, $this> */
    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /** @return HasMany<IntakeActivityEvent, $this> */
    public function activityEvents(): HasMany
    {
        return $this->hasMany(IntakeActivityEvent::class);
    }

    /** @return HasMany<PipeRouteSession, $this> */
    public function pipeRouteSessions(): HasMany
    {
        return $this->hasMany(PipeRouteSession::class)->latest('id');
    }

    /** @return HasMany<DossierSubject, $this> */
    public function dossierSubjects(): HasMany
    {
        return $this->hasMany(DossierSubject::class);
    }

    /** @return HasMany<DossierRecord, $this> */
    public function dossierRecords(): HasMany
    {
        return $this->hasMany(DossierRecord::class);
    }

    /** @return HasMany<DossierDecisionArea, $this> */
    public function decisionAreas(): HasMany
    {
        return $this->hasMany(DossierDecisionArea::class);
    }

    /** @return HasMany<ContributionTask, $this> */
    public function contributionTasks(): HasMany
    {
        return $this->hasMany(ContributionTask::class);
    }

    /** @return HasMany<AircoRoom, $this> */
    public function aircoRooms(): HasMany
    {
        return $this->hasMany(AircoRoom::class)->orderBy('sort_order');
    }

    /** @return HasMany<AircoPlacementOption, $this> */
    public function aircoPlacements(): HasMany
    {
        return $this->hasMany(AircoPlacementOption::class);
    }

    /** @return HasMany<AircoInstallationOption, $this> */
    public function aircoInstallationOptions(): HasMany
    {
        return $this->hasMany(AircoInstallationOption::class)->orderByRaw('rank is null, rank')->orderBy('id');
    }

    /** @return HasMany<AircoConnection, $this> */
    public function aircoConnections(): HasMany
    {
        return $this->hasMany(AircoConnection::class);
    }

    /** @return HasOne<InstallationOutcome, $this> */
    public function outcome(): HasOne
    {
        return $this->hasOne(InstallationOutcome::class);
    }

    public function customerUrl(): string
    {
        return url('/o/'.$this->access_token);
    }

    public function isTokenValid(): bool
    {
        if (! $this->customer_access_enabled || $this->token_revoked_at !== null) {
            return false;
        }

        if ($this->token_expires_at !== null && $this->token_expires_at->isPast()) {
            return false;
        }

        return $this->status->isCustomerAccessible();
    }

    public function fullAddress(): string
    {
        return collect([
            $this->address_line,
            trim(($this->address_postal_code ?? '').' '.($this->address_city ?? '')),
        ])->filter()->implode(', ');
    }
}
