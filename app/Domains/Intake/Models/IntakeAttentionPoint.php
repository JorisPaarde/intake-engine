<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\AttentionPointSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeAttentionPoint extends Model
{
    protected $fillable = [
        'intake_id',
        'source',
        'code',
        'label',
        'is_resolved',
        'resolved_at',
        'resolved_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AttentionPointSource::class,
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
