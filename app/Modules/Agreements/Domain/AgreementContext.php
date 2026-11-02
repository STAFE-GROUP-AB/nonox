<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

use Carbon\CarbonImmutable;

/**
 * What a payslip's calculation knows about the employment's collective
 * agreement (ADR-033 §1): the verified versions that cover the period and
 * the payment date, and the employment's agreement facts. Carried in
 * PayrollContext::$extensions['agreements']; pure.
 */
final readonly class AgreementContext
{
    public const string KEY = 'agreements';

    /**
     * @param  list<array{id: int, valid_from: CarbonImmutable, valid_to: ?CarbonImmutable, rules: AgreementRules}>  $versions  verified, oldest first
     */
    public function __construct(
        public string $agreementKey,
        public string $variant,
        public array $versions,
        public ?string $group,
        public ?CarbonImmutable $experienceFrom,
        public CarbonImmutable $employmentStart,
        public ?string $overtimeCompensation,
        public ?string $obCompensation,
        public bool $minimumWageWarnings = true,
        public bool $sickPayDirectTransfer = false,
        public ?int $fixedSupplementsOre = null,
    ) {}

    /**
     * The version in force on a day: the newest that covers it.
     *
     * @return ?array{id: int, valid_from: CarbonImmutable, valid_to: ?CarbonImmutable, rules: AgreementRules}
     */
    public function versionOn(CarbonImmutable $day): ?array
    {
        $found = null;

        foreach ($this->versions as $version) {
            if ($version['valid_from']->lessThanOrEqualTo($day) && ($version['valid_to'] === null || $version['valid_to']->greaterThanOrEqualTo($day))) {
                $found = $version;
            }
        }

        return $found;
    }
}
