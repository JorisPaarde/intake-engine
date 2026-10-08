<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $intake_id
 * @property string $token_hash
 * @property Carbon $replaced_at
 */
class IntakeReplacedAccessToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'intake_id',
        'token_hash',
        'replaced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'replaced_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
