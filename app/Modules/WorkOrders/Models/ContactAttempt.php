<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Modules\WorkOrders\Domain\ContactChannel;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A try to reach the customer about an ÄTA (AO-R4): when and how. Kept as
 * evidence, append-only.
 *
 * @property int $id
 * @property int $company_id
 * @property int $change_order_id
 * @property CarbonImmutable $attempted_at
 * @property ContactChannel $channel
 * @property ?string $note
 * @property ?int $created_by
 */
class ContactAttempt extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected $table = 'change_order_contact_attempts';

    /** @var list<string> */
    protected $fillable = ['company_id', 'change_order_id', 'attempted_at', 'channel', 'note', 'created_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A contact attempt cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A contact attempt cannot be removed.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attempted_at' => 'immutable_datetime', 'channel' => ContactChannel::class];
    }
}
