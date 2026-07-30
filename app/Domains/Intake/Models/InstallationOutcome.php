<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationOutcome extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'recorded_by',
        'selected_installation_option_id',
        'result',
        'active_installer_minutes',
        'customer_minutes',
        'site_visit_occurred',
        'site_visit_reasons',
        'quote_type',
        'installation_surprise',
        'surprise_notes',
        'proposal_delta',
        'installed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'recorded_by' => 'integer',
            'selected_installation_option_id' => 'integer',
            'active_installer_minutes' => 'integer',
            'customer_minutes' => 'integer',
            'site_visit_occurred' => 'boolean',
            'site_visit_reasons' => 'array',
            'proposal_delta' => 'array',
            'installed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<AircoInstallationOption, $this> */
    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(AircoInstallationOption::class, 'selected_installation_option_id');
    }
}
