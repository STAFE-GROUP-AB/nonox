<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A booked K2-to-K3 transition (ADR-059 A.2.8, H7 (a), S1-P12): one per
 * company and first K3 year, written when a person confirms the proposal.
 * The voucher (source K3_TRANSITION, dated the transition date, against
 * 2091) is null when no adjustment changes the books. Append-only: the
 * model and a database trigger refuse every change; a wrong transition is
 * corrected with a correcting voucher.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property CarbonImmutable $transition_date
 * @property ?string $voucher_id
 * @property ?int $rate_bp
 * @property Money $equity_effect_ore
 * @property bool $comparatives_restated
 * @property bool $chapter_35_repeated
 * @property int $confirmed_by
 * @property ?array<string, int> $opening_balances account number => öre at the transition date, before the transition
 * @property bool $deferred_tax_set_off the right of set-off (K3 29.32) confirmed when booked
 * @property ?CarbonImmutable $created_at
 */
class K3Transition extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'transition_date',
        'voucher_id',
        'rate_bp',
        'equity_effect_ore',
        'comparatives_restated',
        'chapter_35_repeated',
        'confirmed_by',
        'opening_balances',
        'deferred_tax_set_off',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transition_date' => 'immutable_date',
            'equity_effect_ore' => MoneyCast::class,
            'rate_bp' => 'integer',
            'comparatives_restated' => 'boolean',
            'chapter_35_repeated' => 'boolean',
            'opening_balances' => 'array',
            'deferred_tax_set_off' => 'boolean',
        ];
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return HasMany<K3TransitionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(K3TransitionItem::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A booked K3 transition cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A booked K3 transition cannot be removed.');
        });
    }
}
