<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceRounding;
use App\Domain\Pricing\PriceUpdateKind;
use App\Domain\Pricing\PriceUpdateStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mass price update, "Prisuppdatering" (ADR-082 §8): what was changed (ids
 * only), by which rule and rounding, from which date, and what came of it.
 * Planned by PlanPriceUpdate, written by ApplyPriceUpdate (base prices with a
 * later date by pricing:apply-scheduled), undone by RevertPriceUpdate. The
 * lines hold the preview and the diff; an uploaded file is kept for two
 * years and then pruned (decision 10).
 *
 * @property int $id
 * @property int $company_id
 * @property PriceUpdateKind $kind
 * @property array<string, mixed> $scope
 * @property array<string, mixed> $rule
 * @property PriceRounding $rounding_rule
 * @property CarbonImmutable $effective_date
 * @property PriceUpdateStatus $status
 * @property ?array<string, int> $counts
 * @property ?string $file_path
 * @property ?string $file_sha256
 * @property ?string $file_format
 * @property ?CarbonImmutable $file_deleted_at
 * @property int $created_by
 * @property ?CarbonImmutable $applied_at
 * @property ?CarbonImmutable $reverted_at
 * @property ?int $reverted_by
 * @property ?CarbonImmutable $created_at
 */
class PriceUpdateRun extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'kind', 'scope', 'rule', 'rounding_rule', 'effective_date', 'status', 'counts',
        'file_path', 'file_sha256', 'file_format', 'file_deleted_at', 'created_by', 'applied_at', 'reverted_at', 'reverted_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['rounding_rule' => 'NONE', 'status' => 'PREVIEW'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => PriceUpdateKind::class,
            'scope' => 'array',
            'rule' => 'array',
            'rounding_rule' => PriceRounding::class,
            'effective_date' => 'immutable_date',
            'status' => PriceUpdateStatus::class,
            'counts' => 'array',
            'file_deleted_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'reverted_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PriceUpdateRunLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PriceUpdateRunLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A run can be undone until its effective date has passed (ADR-082 §8):
     * afterwards documents may carry its prices, and a new run keeps the
     * history honest.
     */
    public function canRevert(): bool
    {
        return in_array($this->status, [PriceUpdateStatus::Applied, PriceUpdateStatus::Scheduled], true)
            && $this->effective_date->greaterThanOrEqualTo(CarbonImmutable::today());
    }
}
