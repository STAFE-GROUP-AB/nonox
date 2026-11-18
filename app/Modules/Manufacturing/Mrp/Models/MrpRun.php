<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One planning run (ADR-081 gap 5): the weeks it covered, who ran it, the
 * hash of its inputs, the engine version, its counts, and the advice on
 * existing orders. Deleted with its planned orders after 30 days.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $starts_on
 * @property int $horizon_weeks
 * @property ?int $run_by
 * @property string $inputs_hash
 * @property string $engine_version
 * @property int $article_count
 * @property int $planned_order_count
 * @property int $exception_count
 * @property int $message_count
 * @property list<array{kind: string, article_id: int, source: string, id: int, number: int, on: string, to: ?string, quantity_milli: int, order_milli: int}> $messages
 * @property ?CarbonImmutable $created_at
 */
class MrpRun extends Model
{
    use BelongsToCompany;

    public const int KEEP_DAYS = 30;

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['company_id', 'starts_on', 'horizon_weeks', 'run_by', 'inputs_hash', 'engine_version', 'article_count', 'planned_order_count', 'exception_count', 'message_count', 'messages'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'messages' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'horizon_weeks' => 'integer',
            'article_count' => 'integer',
            'planned_order_count' => 'integer',
            'exception_count' => 'integer',
            'message_count' => 'integer',
            'messages' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<MrpPlannedOrder, $this> */
    public function plannedOrders(): HasMany
    {
        return $this->hasMany(MrpPlannedOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
