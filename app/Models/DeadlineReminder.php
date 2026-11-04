<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A reminder already sent about a date on Mina datum (ADR-051, stage 2):
 * which user, which deadline (its key and date) and how many days before.
 * Unique, so a reminder is never sent twice, also if the command runs again.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $deadline_key
 * @property CarbonImmutable $deadline_date
 * @property int $days_before
 * @property CarbonImmutable $sent_at
 */
class DeadlineReminder extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'deadline_key', 'deadline_date', 'days_before', 'sent_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'deadline_date' => 'immutable_date',
            'days_before' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
