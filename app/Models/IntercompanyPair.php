<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Group\IntercompanyKind;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a paired intra-group transaction without an invoice (ADR-081
 * E27): the company's own voucher, and the counterpart's voucher it mirrors,
 * named by organisation number and by series and number as text, never by
 * another tenant's id. Each company keeps its own row.
 *
 * @property int $id
 * @property int $company_id
 * @property string $voucher_id
 * @property string $counterparty_org_number formatted, NNNNNN-NNNN
 * @property ?string $counterparty_voucher_ref series and number, for example "A12"
 * @property IntercompanyKind $kind
 * @property ?int $created_by
 * @property-read ?Voucher $voucher
 */
class IntercompanyPair extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'voucher_id', 'counterparty_org_number', 'counterparty_voucher_ref', 'kind', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => IntercompanyKind::class];
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
