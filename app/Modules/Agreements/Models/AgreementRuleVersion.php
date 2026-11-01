<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Models;

use App\Modules\Agreements\Domain\AgreementRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of a collective agreement's facts (ADR-033 §2): global, the
 * same for every company. Counts in a payroll run only once verified for the
 * installation (verified_by/verified_at, `agreements:verify` command) or
 * checked by the company (AgreementRuleVerification).
 *
 * @property int $id
 * @property string $agreement_key
 * @property string $variant
 * @property CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property array<string, mixed> $rules
 * @property list<array<string, mixed>> $sources
 * @property string $checksum
 * @property ?string $verified_by
 * @property ?CarbonImmutable $verified_at
 */
class AgreementRuleVersion extends Model
{
    public const string MEMBER = 'MEMBER';

    public const string HANGAVTAL = 'HANGAVTAL';

    public const array VARIANTS = [self::MEMBER, self::HANGAVTAL];

    /** @var list<string> */
    protected $fillable = [
        'agreement_key',
        'variant',
        'valid_from',
        'valid_to',
        'rules',
        'sources',
        'checksum',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'rules' => 'array',
            'sources' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function covers(CarbonImmutable $day): bool
    {
        return $this->valid_from->lessThanOrEqualTo($day) && ($this->valid_to === null || $this->valid_to->greaterThanOrEqualTo($day));
    }

    public function agreementRules(): AgreementRules
    {
        return new AgreementRules($this->rules, $this->sources);
    }

    public static function variantLabel(string $variant): string
    {
        return $variant === self::HANGAVTAL ? __('Hängavtal') : __('Medlem i arbetsgivarorganisationen');
    }
}
