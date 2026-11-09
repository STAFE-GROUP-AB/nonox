<?php

declare(strict_types=1);

namespace App\Modules\Saas\Models;

use App\Models\User;
use App\Modules\Saas\Domain\Movement;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One month of the company's recurring revenue (ADR-080 §3.2): the totals of
 * the bridge and the retention figures. OPEN months are recomputed; once a
 * person closes a month it is frozen (the model and a trigger refuse every
 * change) and later knowledge arrives as corrections in the first open month.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $month
 * @property string $status
 * @property ?CarbonImmutable $closed_at
 * @property ?int $closed_by
 * @property ?int $versions_known_until
 * @property ?array<string, array<string, int>> $rates month => currency => micros
 * @property int $opening_mrr_ore
 * @property int $opening_customers
 * @property int $mrr_ore
 * @property int $customers
 * @property int $new_ore
 * @property int $reactivation_ore
 * @property int $resumed_ore
 * @property int $expansion_ore
 * @property int $contraction_ore
 * @property int $pause_ore
 * @property int $churn_ore
 * @property int $fx_ore
 * @property int $source_ore
 * @property int $correction_ore
 * @property int $new_customers
 * @property int $churned_customers
 * @property int $churn_from_active_ore
 * @property ?int $nrr_base_ore
 * @property ?int $nrr_ore
 * @property ?int $grr_ore
 * @property int $scheduled_churn_ore
 * @property int $source_issues
 * @property ?list<string> $missing_rates
 * @property ?array<string, list<array{customers: int, mrr_ore: int}>> $cohorts
 * @property ?CarbonImmutable $computed_at
 */
class SaasMonth extends Model
{
    use BelongsToCompany;

    public const string OPEN = 'OPEN';

    public const string CLOSED = 'CLOSED';

    /** The totals that recompute writes. */
    public const array TOTALS = [
        'opening_mrr_ore', 'opening_customers', 'mrr_ore', 'customers', 'new_ore', 'reactivation_ore', 'resumed_ore',
        'expansion_ore', 'contraction_ore', 'pause_ore', 'churn_ore', 'fx_ore', 'source_ore', 'correction_ore', 'new_customers',
        'churned_customers', 'churn_from_active_ore', 'nrr_base_ore', 'nrr_ore', 'grr_ore', 'scheduled_churn_ore',
        'source_issues', 'missing_rates', 'cohorts', 'rates', 'computed_at',
    ];

    protected $table = 'saas_months';

    /** @var list<string> */
    protected $fillable = ['company_id', 'month', ...self::TOTALS];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::OPEN,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'closed_by' => 'integer',
            'versions_known_until' => 'integer',
            'rates' => 'array',
            'missing_rates' => 'array',
            'cohorts' => 'array',
            'computed_at' => 'immutable_datetime',
            'opening_mrr_ore' => 'integer',
            'opening_customers' => 'integer',
            'mrr_ore' => 'integer',
            'customers' => 'integer',
            'new_ore' => 'integer',
            'reactivation_ore' => 'integer',
            'resumed_ore' => 'integer',
            'expansion_ore' => 'integer',
            'contraction_ore' => 'integer',
            'pause_ore' => 'integer',
            'churn_ore' => 'integer',
            'fx_ore' => 'integer',
            'source_ore' => 'integer',
            'correction_ore' => 'integer',
            'new_customers' => 'integer',
            'churned_customers' => 'integer',
            'churn_from_active_ore' => 'integer',
            'nrr_base_ore' => 'integer',
            'nrr_ore' => 'integer',
            'grr_ore' => 'integer',
            'scheduled_churn_ore' => 'integer',
            'source_issues' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $month): void {
            if ($month->getOriginal('status') === self::CLOSED) {
                throw new LogicException('A closed month cannot be changed.');
            }
        });

        static::deleting(function (self $month): void {
            if ($month->getOriginal('status') === self::CLOSED) {
                throw new LogicException('A closed month cannot be deleted.');
            }
        });
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    public function key(): string
    {
        return $this->month->toDateString();
    }

    public function arrOre(): int
    {
        return $this->mrr_ore * 12;
    }

    /** ARPA: MRR per customer with MRR (SA-R19a). */
    public function arpaOre(): ?int
    {
        return $this->customers === 0 ? null : intdiv(2 * $this->mrr_ore + $this->customers, 2 * $this->customers);
    }

    public function movement(Movement $movement): int
    {
        return (int) $this->getAttribute($movement->column());
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
