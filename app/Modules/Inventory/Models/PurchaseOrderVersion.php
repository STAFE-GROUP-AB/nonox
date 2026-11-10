<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A purchase order as it was sent (ADR-071 §2: "the earlier version is
 * kept"): its header and rows at the moment of sending, how it went and to
 * whom. Written once, never changed.
 *
 * @property int $id
 * @property int $company_id
 * @property int $purchase_order_id
 * @property int $version
 * @property array<string, mixed> $snapshot
 * @property int $total_ore
 * @property string $method
 * @property ?string $sent_to
 * @property ?int $sent_by
 * @property CarbonImmutable $sent_at
 */
class PurchaseOrderVersion extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'purchase_order_id',
        'version',
        'snapshot',
        'total_ore',
        'method',
        'sent_to',
        'sent_by',
        'sent_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A sent purchase order version is never changed.'));
        static::deleting(fn () => throw new LogicException('A sent purchase order version is never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
            'total_ore' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
