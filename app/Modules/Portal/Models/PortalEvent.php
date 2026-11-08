<?php

declare(strict_types=1);

namespace App\Modules\Portal\Models;

use App\Modules\Portal\Domain\EventType;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What a contact did in the portal (ADR-075 §8), shown to the company on
 * the invoice ("Öppnad i kundportalen") because it matters for reminders
 * and disputes. Append-only (PostgreSQL trigger and here), ids only; the
 * IP address and browser are kept on SIGNED_IN only. Pruned after 24
 * months.
 *
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property ?int $contact_id
 * @property EventType $event
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property ?string $ip
 * @property ?string $user_agent
 * @property CarbonImmutable $created_at
 */
class PortalEvent extends Model
{
    use BelongsToCompany;

    public const int RETENTION_MONTHS = 24;

    public const null UPDATED_AT = null;

    protected $table = 'customer_portal_events';

    /** @var list<string> */
    protected $fillable = ['company_id', 'customer_id', 'contact_id', 'event', 'subject_type', 'subject_id', 'ip', 'user_agent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['event' => EventType::class, 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Customer portal events are append-only (ADR-075).'));
    }

    /** @return BelongsTo<PortalContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(PortalContact::class, 'contact_id');
    }
}
