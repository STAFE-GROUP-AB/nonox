<?php

declare(strict_types=1);

namespace App\Actions\IncomeTax;

use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\IncomeTax\Ink2;
use App\Domain\IncomeTax\Ink2Codes;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Sru\SruFile;
use App\Support\Sru\SruZip;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The INK2 income tax return: saving what a person adds, and the SRU files
 * for Skatteverket's file transfer service. Needs fiscal-years:manage.
 */
class IncomeTaxReturns
{
    /**
     * An empty field is not saved; for the fields in Ink2Codes::PROPOSED it
     * then keeps the proposal from the books.
     *
     * @param  array<int|string, string>  $adjustments  amounts in kronor by field code
     */
    public function save(User $user, FiscalYear $fiscalYear, array $adjustments, ?bool $consultant, ?bool $audited): IncomeTaxReturn
    {
        $company = $this->authorize($user, $fiscalYear);
        $amounts = [];

        foreach (array_keys(Ink2Codes::enteredLabels()) as $code) {
            $value = trim((string) ($adjustments[$code] ?? $adjustments[(string) $code] ?? ''));

            if ($value === '') {
                continue;
            }

            try {
                $kronor = Ink2::kronor(Money::parse($value)->ore);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["adjustments.{$code}" => __('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.')]);
            }

            if ($kronor < 0) {
                throw ValidationException::withMessages(["adjustments.{$code}" => __('Skriv beloppet utan minustecken.')]);
            }

            // A proposed field keeps an entered 0: it replaces the proposal.
            if ($kronor > 0 || in_array($code, Ink2Codes::PROPOSED, true)) {
                $amounts[$code] = $kronor;
            }
        }

        if (($amounts[Ink2Codes::PENSION_BASIS] ?? 0) > 0 && ($amounts[Ink2Codes::PENSION_BASIS_NEGATIVE] ?? 0) > 0) {
            throw ValidationException::withMessages(['adjustments.'.Ink2Codes::PENSION_BASIS_NEGATIVE => __('Fyll i antingen underlaget eller det negativa underlaget för särskild löneskatt, inte båda.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $amounts, $consultant, $audited): IncomeTaxReturn {
            $return = IncomeTaxReturn::query()->updateOrCreate(['fiscal_year_id' => $fiscalYear->id, 'form' => IncomeTaxReturn::INK2], [
                'adjustments' => $amounts,
                'consultant' => $consultant,
                'audited' => $audited,
                'updated_by' => $user->id,
            ]);
            Audit::record('income_tax.saved', $return, ['fiscal_year' => $fiscalYear->label(), 'adjustments' => $amounts], $company, $user);

            return $return;
        });
    }

    /**
     * The SRU delivery as a ZIP with INFO.SRU and BLANKETTER.SRU.
     */
    public function sruZip(User $user, FiscalYear $fiscalYear): string
    {
        $company = $this->authorize($user, $fiscalYear);

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear): string {
            $ink2 = Ink2::for($company, $fiscalYear, IncomeTaxReturn::query()->where('fiscal_year_id', $fiscalYear->id)->where('form', IncomeTaxReturn::INK2)->first());

            if (! $ink2->isVerifiedPeriod()) {
                throw ValidationException::withMessages(['period' => __('Fältkoderna för beskattningsperiod :period är inte kontrollerade ännu. Fyll i deklarationen i Skatteverkets e-tjänst.', ['period' => $ink2->period()])]);
            }

            if (blank($company->postal_code) || blank($company->city)) {
                throw ValidationException::withMessages(['address' => __('Fyll i företagets postnummer och ort under Företagsuppgifter först.')]);
            }

            $identity = $ink2->identity();
            $name = (string) ($company->legal_name ?: $company->name);
            $period = $ink2->period();
            $files = SruFile::render(
                ['orgnr' => $identity, 'name' => $name, 'postal_code' => (string) $company->postal_code, 'city' => (string) $company->city, 'address' => $company->address_line1, 'email' => $company->email, 'phone' => $company->phone],
                [
                    ['form' => "INK2-{$period}", 'identity' => $identity, 'name' => $name, 'fields' => $ink2->ink2()],
                    ['form' => "INK2R-{$period}", 'identity' => $identity, 'name' => $name, 'fields' => $ink2->ink2r()],
                    ['form' => "INK2S-{$period}", 'identity' => $identity, 'name' => $name, 'fields' => $ink2->ink2s()],
                ],
                now()->toImmutable(),
                'NONOX Ekonomisystem',
            );

            $content = SruZip::of($files);

            Audit::record('income_tax.sru_exported', $fiscalYear, ['period' => $period], $company, $user);

            return $content;
        });
    }

    private function authorize(User $user, FiscalYear $fiscalYear): Company
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        if (! AnnualReportDocument::supportsLegalForm($company)) {
            throw ValidationException::withMessages(['form' => __('Inkomstdeklaration 2 gäller aktiebolag och ekonomiska föreningar.')]);
        }

        return $company;
    }
}
