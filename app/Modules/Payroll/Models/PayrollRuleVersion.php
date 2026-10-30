<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of a payroll rule (PAYROLL_MODULE.md B.6). Global: the same
 * rules apply to every company.
 *
 * @property int $id
 * @property string $key
 * @property CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property mixed $value
 * @property string $source_url
 * @property ?string $source_quote
 * @property ?string $verified_by
 * @property ?CarbonImmutable $verified_at
 */
class PayrollRuleVersion extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'key',
        'valid_from',
        'valid_to',
        'value',
        'source_url',
        'source_quote',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'value' => 'json',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
