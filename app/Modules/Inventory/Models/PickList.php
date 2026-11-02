<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Customer;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plocklista: what to fetch for an order or invoice (or a manual list),
 * sorted by location so the picker walks the warehouse once. Completing it
 * posts PICK movements for the picked quantities.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property ?string $source_type
 * @property ?string $source_id
 * @property ?string $source_label
 * @property ?int $customer_id
 * @property string $status
 * @property ?string $note
 * @property ?CarbonImmutable $completed_at
 * @property ?int $completed_by
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class PickList extends Model
{
    use BelongsToCompany;

    public const string OPEN = 'OPEN';

    public const string COMPLETED = 'COMPLETED';

    public const string CANCELLED = 'CANCELLED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'source_type',
        'source_id',
        'source_label',
        'customer_id',
        'status',
        'note',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OPEN => __('Att plocka'),
            self::COMPLETED => __('Plockad'),
            default => __('Makulerad'),
        };
    }

    /** @return HasMany<PickListLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PickListLine::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
