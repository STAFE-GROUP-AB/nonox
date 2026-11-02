<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

/**
 * What AgreementPayTerms resolved before the calculation for the steps
 * after it (ADR-033 A9–A11): the agreement insurances with their premiums
 * from the verified payroll rule, a fee of the agreement's own, and
 * warnings about terms NONOX does not calculate. Carried in
 * PayrollContext::$extensions['agreement_costs']; pure.
 */
final readonly class AgreementCosts
{
    public const string KEY = 'agreement_costs';

    /**
     * @param  list<array{key: string, name: string, bp: int, cap_ore: ?int, from_age: ?int, to_age_exclusive: ?int}>  $components  premiums on the cash pay, in basis points
     * @param  ?array{bp: int, source: string}  $adminFee  a fee on the premium basis, such as the hängavtal fee
     * @param  array<string, mixed>  $explanation  the agreement and rule version the premiums follow
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $components = [],
        public ?int $ruleVersionId = null,
        public ?array $adminFee = null,
        public array $explanation = [],
        public array $warnings = [],
    ) {}
}
