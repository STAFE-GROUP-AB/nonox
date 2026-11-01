<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's check of one agreement version against its sources, by a
 * named user. Append-only.
 *
 * @property int $agreement_rule_version_id
 * @property int $verified_by
 * @property string $verifier_name
 * @property CarbonImmutable $verified_at
 */
class AgreementRuleVerification extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'agreement_rule_version_id',
        'verified_by',
        'verifier_name',
        'verified_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime'];
    }
}
