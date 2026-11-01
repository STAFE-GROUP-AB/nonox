<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The company's binding to a collective agreement (ADR-033 §3): which
 * agreement (agreement_key, from NONOX Avtal's versioned data), whether as
 * a member of the employers' organisation or by hängavtal, from and to
 * when, and local overrides. A row without agreement_key is a company's own
 * configurable template (PAYROLL_MODULE.md B.8). Rules only, never
 * agreement text.
 *
 * @property int $id
 * @property string $name
 * @property ?string $agreement_key
 * @property ?string $variant
 * @property ?CarbonImmutable $bound_from
 * @property ?CarbonImmutable $bound_to
 * @property array<string, mixed> $overrides
 */
class CollectiveAgreement extends Model
{
    use BelongsToCompany;

    protected $table = 'collective_agreements';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'rules',
        'agreement_key',
        'variant',
        'bound_from',
        'bound_to',
        'overrides',
        'local_agreement_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'overrides' => 'array',
            'bound_from' => 'immutable_date',
            'bound_to' => 'immutable_date',
        ];
    }
}
