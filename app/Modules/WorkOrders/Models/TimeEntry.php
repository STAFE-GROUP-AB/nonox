<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Models\User;
use App\Modules\WorkOrders\Models\Concerns\AppendOnlyEntry;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Billable time on a work order (ADR-074 §3): not pay time and not
 * attendance. Hours are minutes; travel per trip or km is a quantity in
 * thousandths. A reversal carries the negative amount.
 *
 * @property string $id
 * @property int $company_id
 * @property int $work_order_id
 * @property ?int $change_order_id
 * @property int $user_id
 * @property int $labour_kind_id
 * @property CarbonImmutable $work_date
 * @property ?int $minutes
 * @property ?int $quantity_milli
 * @property bool $billable
 * @property int $rate_ore per hour or unit, excluding VAT
 * @property ?string $note
 * @property string $source MOBILE, WEB or STAFF_LEDGER_PROPOSAL
 * @property CarbonImmutable $entered_at
 * @property ?string $reverses_id
 * @property ?string $reversed_by_id
 * @property ?string $reversal_reason
 * @property ?string $invoice_id
 * @property ?int $created_by
 */
class TimeEntry extends Model
{
    use AppendOnlyEntry;
    use BelongsToCompany;
    use HasUuids;

    public const array SOURCES = ['MOBILE', 'WEB', 'STAFF_LEDGER_PROPOSAL'];

    protected $table = 'work_order_time_entries';

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'work_order_id', 'change_order_id', 'user_id', 'labour_kind_id', 'work_date', 'minutes',
        'quantity_milli', 'billable', 'rate_ore', 'note', 'source', 'entered_at', 'reverses_id', 'reversal_reason', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'minutes' => 'integer',
            'quantity_milli' => 'integer',
            'billable' => 'boolean',
            'rate_ore' => 'integer',
            'entered_at' => 'immutable_datetime',
        ];
    }

    /** The amount excluding VAT: hours × rate, or quantity × rate, rounded half up to whole öre. */
    public function netOre(): int
    {
        $numerator = $this->minutes !== null ? $this->minutes * $this->rate_ore : (int) $this->quantity_milli * $this->rate_ore;
        $divisor = $this->minutes !== null ? 60 : 1000;

        return (int) round($numerator / $divisor, 0, PHP_ROUND_HALF_UP);
    }

    /** @return BelongsTo<LabourKind, $this> */
    public function labourKind(): BelongsTo
    {
        return $this->belongsTo(LabourKind::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
