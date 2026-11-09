<?php

declare(strict_types=1);

namespace App\Modules\Saas\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A register agreement and an avtalsfakturering of the same customer that a
 * person has marked as separate agreements (re-check P3), so they are no
 * longer listed as possible double counting. Ids only; removing the row
 * undoes the marking.
 *
 * @property int $id
 * @property int $company_id
 * @property int $crm_agreement_id
 * @property string $recurring_invoice_id
 * @property ?int $created_by
 */
class SaasSeparatePair extends Model
{
    use BelongsToCompany;

    protected $table = 'saas_separate_pairs';

    /** @var list<string> */
    protected $fillable = ['company_id', 'crm_agreement_id', 'recurring_invoice_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'crm_agreement_id' => 'integer',
            'created_by' => 'integer',
        ];
    }
}
