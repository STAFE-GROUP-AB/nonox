<?php

declare(strict_types=1);

namespace App\Modules\Saas\Models;

use App\Models\Customer;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An estimated MRR for a month before the module was switched on, from the
 * recurring invoices issued then (O9). Labelled "uppskattad" everywhere and
 * kept apart from the ledger: it is never closed, corrected or verified.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $month
 * @property int $customer_id
 * @property int $mrr_ore
 * @property list<string> $invoice_ids
 */
class SaasEstimate extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected $table = 'saas_estimates';

    /** @var list<string> */
    protected $fillable = ['company_id', 'month', 'customer_id', 'mrr_ore', 'invoice_ids'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'customer_id' => 'integer',
            'mrr_ore' => 'integer',
            'invoice_ids' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
