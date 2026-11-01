<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An asset to create when a draft is posted (a voucher) or registered (a
 * supplier invoice): the register's fields for the row at `line`, counted
 * from zero among the draft's saved rows. It goes away with the draft, and
 * is replaced by the asset itself when the purchase is booked.
 *
 * @property int $id
 * @property int $company_id
 * @property ?string $voucher_id
 * @property ?string $supplier_invoice_id
 * @property int $line
 * @property array{name: string, category: string, acquisition: string, life_months: int, acquired_on: ?string, depreciation_start: ?string} $data
 * @property int $created_by
 */
class FixedAssetProposal extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'voucher_id',
        'supplier_invoice_id',
        'line',
        'data',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'data' => 'array',
        ];
    }
}
