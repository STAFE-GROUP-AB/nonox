<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The figures a person saw for one project's work in progress on a balance
 * sheet date when they asked for draft vouchers (ADR-081 gap 6, E8), and the
 * drafts made. Append-only: the model refuses changes, and so does a
 * database trigger.
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property CarbonImmutable $as_of
 * @property string $method
 * @property int $contract_ore
 * @property int $costs_ore
 * @property ?int $estimated_cost_ore
 * @property ?int $degree_bp
 * @property ?int $earned_ore
 * @property int $invoiced_ore
 * @property int $adjustment_ore
 * @property int $revenue_account
 * @property ?string $voucher_id
 * @property ?string $reversal_voucher_id
 * @property int $created_by
 * @property ?CarbonInterface $created_at
 */
class ProjectWipSnapshot extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'project_id', 'as_of', 'method', 'contract_ore', 'costs_ore', 'estimated_cost_ore', 'degree_bp',
        'earned_ore', 'invoiced_ore', 'adjustment_ore', 'revenue_account', 'voucher_id', 'reversal_voucher_id', 'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Project WIP snapshots cannot be changed.'));
        static::deleting(fn (): never => throw new LogicException('Project WIP snapshots cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_date',
            'contract_ore' => 'integer',
            'costs_ore' => 'integer',
            'estimated_cost_ore' => 'integer',
            'degree_bp' => 'integer',
            'earned_ore' => 'integer',
            'invoiced_ore' => 'integer',
            'adjustment_ore' => 'integer',
            'revenue_account' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
