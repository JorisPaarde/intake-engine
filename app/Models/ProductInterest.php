<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $company_name
 * @property string $contact_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $message
 * @property \Illuminate\Support\Carbon|null $notification_queued_at
 * @property \Illuminate\Support\Carbon $expires_at
 */
#[Fillable([
    'company_name',
    'contact_name',
    'email',
    'phone',
    'message',
    'notification_queued_at',
    'expires_at',
])]
final class ProductInterest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_queued_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
