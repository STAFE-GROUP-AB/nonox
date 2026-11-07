<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3PolicyValue;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One K3 accounting policy of a company, in force from the start of a fiscal
 * year until a row with a later applies_from replaces it (ADR-059, addendum
 * 2026-11-07, A.2.3). Written only by SetAccountingPolicy.
 *
 * @property int $id
 * @property int $company_id
 * @property K3PolicyKey $key
 * @property string $value one of $key->values()
 * @property CarbonImmutable $applies_from
 * @property ?int $set_by
 * @property ?string $reason
 */
class CompanyAccountingPolicy extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'key',
        'value',
        'applies_from',
        'set_by',
        'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'key' => K3PolicyKey::class,
            'applies_from' => 'immutable_date',
            'set_by' => 'integer',
        ];
    }

    public function policyValue(): ?K3PolicyValue
    {
        return $this->key->valueOf($this->value);
    }
}
