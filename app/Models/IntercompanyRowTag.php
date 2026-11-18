<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanyTagSource;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tag beside one row of a posted voucher: which group company the row is
 * with and what kind of intra-group item it is (ADR-060, addendum
 * 2026-11-07, 2.3). Classification metadata, not räkenskapsinformation: it
 * may be changed and removed (each change audited), and the voucher row
 * itself, the voucher triggers and the hash chain are never touched. A
 * trigger refuses a tag on a row of a draft or of another company.
 *
 * @property int $id
 * @property int $company_id
 * @property int $voucher_row_id
 * @property string $counterparty_org_number formatted, NNNNNN-NNNN
 * @property IntercompanyKind $kind
 * @property IntercompanyTagSource $source
 * @property ?int $tagged_by
 * @property-read VoucherRow $row
 */
class IntercompanyRowTag extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'voucher_row_id', 'counterparty_org_number', 'kind', 'source', 'tagged_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => IntercompanyKind::class,
            'source' => IntercompanyTagSource::class,
        ];
    }

    /** @return BelongsTo<VoucherRow, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(VoucherRow::class, 'voucher_row_id');
    }
}
