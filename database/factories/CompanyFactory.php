<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Company\CreateCompany;
use App\Domain\Company\AccountingMethod;
use App\Domain\Company\LegalForm;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatPeriod;
use App\Models\Company;
use App\Models\User;
use App\Support\Luhn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $number = self::organisationNumber();
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'user_id' => User::factory(),
            'personal_team' => false,
            'org_number' => $number->formatted(),
            'legal_form' => LegalForm::Ab,
            'legal_name' => $name,
            'vat_number' => $number->vatNumber(),
            'country' => 'SE',
            'fiscal_year_start_month' => 1,
            'accounting_method' => AccountingMethod::Fakturametoden,
            'vat_period' => VatPeriod::Quarterly,
            'vat_registered' => true,
            'reporting_framework' => ReportingFramework::K2,
            // As if K2 was chosen with the conditions confirmed; a test of the reconfirmation clears it.
            'k2_conditions_confirmed_at' => now(),
            'bas_version' => CreateCompany::BAS_VERSION,
            'f_skatt' => true,
            'employer_registered' => false,
            'base_currency' => 'SEK',
        ];
    }

    /**
     * A synthetic, Luhn-valid organisation number in the aktiebolag range.
     */
    public static function organisationNumber(): OrganisationNumber
    {
        $body = fake()->unique()->numerify('559######');

        return OrganisationNumber::tryFrom($body.Luhn::checkDigit($body))
            ?? throw new \LogicException('Generated organisation number is invalid.');
    }
}
