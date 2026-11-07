<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Group\ControlBasis;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shares the company holds in another legal person (ADR-060, addendum
 * 2026-11-07, 2.1): the holder's own register of shares in group companies,
 * which its ledger already reflects (BAS 131x). The held company is named by
 * organisation number and name, never by another tenant's id.
 *
 * @property int $id
 * @property int $company_id the holder
 * @property string $held_org_number formatted, NNNNNN-NNNN
 * @property string $held_name
 * @property int $capital_bp share of the capital in basis points
 * @property int $voting_bp share of the votes in basis points
 * @property ControlBasis $control_basis
 * @property CarbonImmutable $acquired_on
 * @property ?CarbonImmutable $disposed_on
 * @property ?bool $common_control null while not answered
 */
class CompanyHolding extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'held_org_number',
        'held_name',
        'capital_bp',
        'voting_bp',
        'control_basis',
        'acquired_on',
        'disposed_on',
        'common_control',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capital_bp' => 'integer',
            'voting_bp' => 'integer',
            'control_basis' => ControlBasis::class,
            'acquired_on' => 'immutable_date',
            'disposed_on' => 'immutable_date',
            'common_control' => 'boolean',
        ];
    }

    /**
     * Holdings not disposed of before the day: the ones that count when a
     * company's votes are added up (ADR-060, addendum 2026-11-07, 2.1).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonImmutable $date): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('disposed_on')->orWhere('disposed_on', '>', $date->toDateString()));
    }
}
