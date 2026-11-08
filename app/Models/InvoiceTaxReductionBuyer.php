<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of several ROT or RUT buyers on an invoice (ADR-074 O6), for example
 * two spouses: the buyer's share of the labour and own deduction. Follows
 * the invoice: a database trigger refuses changes once it is issued.
 *
 * @property int $id
 * @property int $company_id
 * @property string $invoice_id
 * @property int $customer_id
 * @property int $share_bp
 * @property Money $deduction_ore
 */
class InvoiceTaxReductionBuyer extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'invoice_id',
        'customer_id',
        'share_bp',
        'deduction_ore',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'share_bp' => 'integer',
            'deduction_ore' => MoneyCast::class,
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
