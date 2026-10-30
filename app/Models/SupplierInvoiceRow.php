<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cost row of a supplier invoice: the account, the amount excluding VAT and
 * optionally a cost centre or project, for example one per restaurant.
 *
 * @property string $supplier_invoice_id
 * @property int $account_id
 * @property ?string $description
 * @property Money $amount_ore
 * @property ?int $cost_center_id
 * @property ?int $project_id
 * @property int $position
 * @property ?int $vat_rate
 */
class SupplierInvoiceRow extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'supplier_invoice_id',
        'account_id',
        'description',
        'amount_ore',
        'cost_center_id',
        'project_id',
        'position',
        'vat_rate',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_ore' => MoneyCast::class,
            'position' => 'integer',
            'vat_rate' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
