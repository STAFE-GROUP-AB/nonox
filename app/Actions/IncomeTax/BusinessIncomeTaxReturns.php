<?php

declare(strict_types=1);

namespace App\Actions\IncomeTax;

use App\Domain\Company\LegalForm;
use App\Domain\IncomeTax\Ink2;
use App\Domain\IncomeTax\Ink4;
use App\Domain\IncomeTax\Ink4Codes;
use App\Domain\IncomeTax\Ne;
use App\Domain\IncomeTax\NeCodes;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxPartner;
use App\Models\IncomeTaxReturn;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Luhn;
use App\Support\Money\Money;
use App\Support\Sru\SruFile;
use App\Support\Sru\SruZip;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\Exception\MathException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The NE-bilaga of a sole trader and Inkomstdeklaration 4 of a handelsbolag
 * or kommanditbolag (ADR-053): saving what a person enters and confirms,
 * the partners of a handelsbolag, and the SRU files. Needs
 * fiscal-years:manage, like INK2. Nothing is booked. The audit log records
 * which fields were saved, never the amounts or the partners' identities.
 */
class BusinessIncomeTaxReturns
{
    /** The largest amount a field takes, far above any real return and far from an integer overflow. */
    private const int MAX_KRONOR = 100_000_000_000;

    /**
     * Saves the NE: amounts by field code, the inputs the calculations use,
     * and the owner's century of birth (personal data, encrypted).
     *
     * @param  array<int|string, string>  $adjustments  kronor by field code
     * @param  array<string, mixed>  $inputs
     */
    public function saveNe(User $user, FiscalYear $fiscalYear, array $adjustments, array $inputs, ?string $century, ?bool $consultant): IncomeTaxReturn
    {
        $company = $this->authorize($user, $fiscalYear, [LegalForm::Ef]);
        $codes = [...array_keys(NeCodes::ADJUSTMENTS), ...array_keys(NeCodes::INFORMATION), NeCodes::BENEFITS, NeCodes::RESERVE_ALLOCATION, NeCodes::EXPANSION_INCREASE, NeCodes::EXPANSION_DECREASE, NeCodes::SELF_EMPLOYMENT_DEDUCTION];
        $amounts = $this->amounts($adjustments, $codes, keepZero: [NeCodes::SELF_EMPLOYMENT_DEDUCTION]);

        if ($century !== null && ! in_array($century, ['19', '20'], true)) {
            throw ValidationException::withMessages(['century' => __('Välj 1900-talet eller 2000-talet.')]);
        }

        $clean = [
            'activity' => mb_substr(trim((string) ($inputs['activity'] ?? '')), 0, 80),
            'passive' => (bool) ($inputs['passive'] ?? false),
            'outside_eea' => (bool) ($inputs['outside_eea'] ?? false),
            'leased_car' => (bool) ($inputs['leased_car'] ?? false),
            'capital_base' => $this->kronor($inputs['capital_base'] ?? '', 'inputs.capital_base', negative: true),
            'positive_allocation' => $this->kronor($inputs['positive_allocation'] ?? '', 'inputs.positive_allocation'),
            'expansion_capital_base' => $this->kronor($inputs['expansion_capital_base'] ?? '', 'inputs.expansion_capital_base', negative: true),
            'expansion_fund_opening' => $this->kronor($inputs['expansion_fund_opening'] ?? '', 'inputs.expansion_fund_opening'),
        ];

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $amounts, $clean, $century, $consultant): IncomeTaxReturn {
            $return = IncomeTaxReturn::query()->updateOrCreate(['fiscal_year_id' => $fiscalYear->id, 'form' => IncomeTaxReturn::NE], [
                'adjustments' => $amounts,
                'inputs' => $clean,
                'personal' => $century === null ? null : ['century' => $century],
                'consultant' => $consultant,
                'audited' => null,
                'updated_by' => $user->id,
            ]);
            Audit::record('income_tax.saved', $return, ['fiscal_year' => $fiscalYear->label(), 'form' => IncomeTaxReturn::NE, 'fields' => array_keys($amounts)], $company, $user);

            return $return;
        });
    }

    /**
     * Saves INK4S: the adjustments and 4.14–4.16 by field code.
     *
     * @param  array<int|string, string>  $adjustments
     */
    public function saveInk4(User $user, FiscalYear $fiscalYear, array $adjustments, ?bool $consultant, ?bool $audited): IncomeTaxReturn
    {
        $company = $this->authorize($user, $fiscalYear, [LegalForm::Hb, LegalForm::Kb]);
        $amounts = $this->amounts($adjustments, [...array_keys(Ink4Codes::ADJUSTMENTS), ...array_keys(Ink4Codes::INFORMATION)], keepZero: [Ink4Codes::S_PENSION_COSTS]);

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $amounts, $consultant, $audited): IncomeTaxReturn {
            $return = IncomeTaxReturn::query()->updateOrCreate(['fiscal_year_id' => $fiscalYear->id, 'form' => IncomeTaxReturn::INK4], [
                'adjustments' => $amounts,
                'consultant' => $consultant,
                'audited' => $audited,
                'updated_by' => $user->id,
            ]);
            Audit::record('income_tax.saved', $return, ['fiscal_year' => $fiscalYear->label(), 'form' => IncomeTaxReturn::INK4, 'fields' => array_keys($amounts)], $company, $user);

            return $return;
        });
    }

    /**
     * Replaces the partners of an open fiscal year. Each: position (the BAS
     * block, 1 for 2010–2019 up to 4 for 2040–2049), name, a personnummer with
     * century (twelve digits) or an organisationsnummer (ten digits), and the
     * share in per cent. An empty number with `ref` (a partner already stored
     * in the company, this year or the one before) keeps the stored number,
     * so the page never has to send it to the browser. A closed year's
     * partners are part of its return and are not changed.
     *
     * @param  array<int, array{ref?: mixed, position?: mixed, name?: mixed, identity?: mixed, legal_person?: mixed, share?: mixed}>  $partners
     */
    public function savePartners(User $user, FiscalYear $fiscalYear, array $partners): void
    {
        $company = $this->authorize($user, $fiscalYear, [LegalForm::Hb, LegalForm::Kb]);

        if (! $fiscalYear->isOpen()) {
            throw ValidationException::withMessages(['partners' => __('Räkenskapsåret är stängt. Delägarna för året kan inte ändras, eftersom de hör till årets deklaration.')]);
        }

        $rows = [];
        $positions = [];
        $stored = CurrentCompany::run($company, fn () => IncomeTaxPartner::query()
            ->whereIn('id', array_filter(array_map(fn (array $partner): int => (int) ($partner['ref'] ?? 0), $partners)))
            ->get()->keyBy('id'));

        foreach ($partners as $index => $partner) {
            $name = trim((string) ($partner['name'] ?? ''));
            $position = (int) ($partner['position'] ?? 0);
            $legal = (bool) ($partner['legal_person'] ?? false);
            $key = "partners.{$index}";

            if ($name === '' || mb_strlen($name) > 200) {
                throw ValidationException::withMessages(["{$key}.name" => __('Skriv delägarens namn.')]);
            }

            if ($position < 1 || $position > Ink4Codes::MAX_PARTNERS_IN_BAS || in_array($position, $positions, true)) {
                throw ValidationException::withMessages(["{$key}.position" => __('Varje delägare behöver ett eget nummer mellan 1 och 4. BAS har kontoblock för fyra delägare (2010–2049), och därifrån hämtas uttag och tillskott. Har bolaget fler delägare, fyll i INK4 i Skatteverkets e-tjänst.')]);
            }

            $positions[] = $position;
            $typed = trim((string) ($partner['identity'] ?? ''));
            $source = $stored->get((int) ($partner['ref'] ?? 0));

            if ($typed === '' && $source instanceof IncomeTaxPartner && $source->legal_person === $legal) {
                $identity = $source->identity;
            } elseif ($typed === '' && ($partner['ref'] ?? null) !== null && $source === null) {
                throw ValidationException::withMessages(["{$key}.identity" => __('Skriv delägarens nummer.')]);
            } else {
                $identity = self::identity($typed, $legal, "{$key}.identity");
            }

            $rows[] = [
                'position' => $position,
                'name' => $name,
                'identity' => $identity,
                'legal_person' => $legal,
                'share_bp' => self::share((string) ($partner['share'] ?? ''), "{$key}.share"),
            ];
        }

        CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $rows): void {
            DB::transaction(function () use ($user, $company, $fiscalYear, $rows): void {
                IncomeTaxPartner::query()->where('fiscal_year_id', $fiscalYear->id)->delete();

                foreach ($rows as $row) {
                    IncomeTaxPartner::query()->create([...$row, 'fiscal_year_id' => $fiscalYear->id, 'updated_by' => $user->id]);
                }

                Audit::record('income_tax.partners_saved', $fiscalYear, ['fiscal_year' => $fiscalYear->label(), 'partners' => count($rows)], $company, $user);
            });
        });
    }

    /** The SRU delivery as a ZIP: NE for a sole trader, INK4R, INK4S and INK4DU for a handelsbolag. */
    public function sruZip(User $user, FiscalYear $fiscalYear): string
    {
        $company = $this->authorize($user, $fiscalYear, [LegalForm::Ef, LegalForm::Hb, LegalForm::Kb]);

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear): string {
            if (blank($company->postal_code) || blank($company->city)) {
                throw ValidationException::withMessages(['address' => __('Fyll i postnummer och ort under Företagsuppgifter först.')]);
            }

            $name = (string) ($company->legal_name ?: $company->name);
            $form = $company->legal_form === LegalForm::Ef ? IncomeTaxReturn::NE : IncomeTaxReturn::INK4;
            $return = IncomeTaxReturn::query()->where('fiscal_year_id', $fiscalYear->id)->where('form', $form)->first();

            if ($form === IncomeTaxReturn::NE) {
                $ne = Ne::for($company, $fiscalYear, $return);
                self::refuseUnverified($ne->isVerifiedPeriod(), $ne->period());

                if (! $ne->hasParameters()) {
                    throw ValidationException::withMessages(['parameters' => __('Procentsatserna för inkomstår :year är inte kontrollerade i NONOX än, så NE-bilagan kan inte räknas klart. Fyll i den i Skatteverkets e-tjänst.', ['year' => $fiscalYear->end_date->year])]);
                }

                $identity = $ne->identity();
                $period = $ne->period();
                $blocks = [['form' => "NE-{$period}", 'identity' => $identity, 'name' => $name, 'fields' => $ne->ne()]];
            } else {
                $ink4 = Ink4::for($company, $fiscalYear, $return, IncomeTaxPartner::query()->where('fiscal_year_id', $fiscalYear->id)->get());
                self::refuseUnverified($ink4->isVerifiedPeriod(), $ink4->period());

                if ($ink4->partners->isEmpty() || $ink4->partners->sum('share_bp') !== 10_000) {
                    throw ValidationException::withMessages(['partners' => __('Delägarnas andelar ska bli 100 % innan filerna kan laddas ner.')]);
                }

                $identity = $ink4->identity();
                $period = $ink4->period();
                $blocks = [
                    ['form' => "INK4R-{$period}", 'identity' => $identity, 'name' => $name, 'fields' => $ink4->ink4r()],
                    ['form' => "INK4S-{$period}", 'identity' => $identity, 'name' => $name, 'fields' => $ink4->ink4s()],
                ];

                // Each partner's INK4DU stands alone in its own #BLANKETT block (SKV's field table).
                foreach ($ink4->ink4du() as $block) {
                    $blocks[] = ['form' => "INK4DU-{$period}", 'identity' => $identity, 'name' => $name, 'fields' => $block['fields']];
                }
            }

            $files = SruFile::render(
                ['orgnr' => $identity, 'name' => $name, 'postal_code' => (string) $company->postal_code, 'city' => (string) $company->city, 'address' => $company->address_line1, 'email' => $company->email, 'phone' => $company->phone],
                $blocks,
                now()->toImmutable(),
                'NONOX Ekonomisystem',
            );

            Audit::record('income_tax.sru_exported', $fiscalYear, ['period' => $period, 'form' => $form], $company, $user);

            return SruZip::of($files);
        });
    }

    /** @param  list<LegalForm>  $forms */
    private function authorize(User $user, FiscalYear $fiscalYear, array $forms): Company
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        if (! in_array($company->legal_form, $forms, true)) {
            throw ValidationException::withMessages(['form' => __('Den här deklarationen gäller inte företagets bolagsform.')]);
        }

        return $company;
    }

    private static function refuseUnverified(bool $verified, string $period): void
    {
        if (! $verified) {
            throw ValidationException::withMessages(['period' => __('Fältkoderna för beskattningsperiod :period är inte kontrollerade ännu. Fyll i deklarationen i Skatteverkets e-tjänst.', ['period' => $period])]);
        }
    }

    /**
     * @param  array<int|string, string>  $input
     * @param  list<int>  $codes
     * @param  list<int>  $keepZero  codes where an entered 0 replaces a proposal
     * @return array<int, int>
     */
    private function amounts(array $input, array $codes, array $keepZero): array
    {
        $amounts = [];

        foreach ($codes as $code) {
            $kronor = $this->kronor($input[$code] ?? $input[(string) $code] ?? '', "adjustments.{$code}");

            if ($kronor !== null && ($kronor > 0 || in_array($code, $keepZero, true))) {
                $amounts[$code] = $kronor;
            }
        }

        return $amounts;
    }

    /** Whole kronor from what was typed, or null when nothing was typed. */
    private function kronor(mixed $value, string $field, bool $negative = false): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $kronor = Ink2::kronor(Money::parse($value)->ore);
        } catch (InvalidArgumentException|MathException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.')]);
        }

        if (! $negative && $kronor < 0) {
            throw ValidationException::withMessages([$field => __('Skriv beloppet utan minustecken.')]);
        }

        if (abs($kronor) > self::MAX_KRONOR) {
            throw ValidationException::withMessages([$field => __('Beloppet är för stort.')]);
        }

        return $kronor;
    }

    /** Twelve digits for SRU: 19/20 and a personnummer, or 16 and an organisationsnummer; Luhn-checked. */
    private static function identity(string $value, bool $legal, string $field): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        if ($legal) {
            if (strlen($digits) === 12 && str_starts_with($digits, '16')) {
                $digits = substr($digits, 2);
            }

            if (strlen($digits) !== 10 || ! Luhn::isValid($digits)) {
                throw ValidationException::withMessages([$field => __('Organisationsnumret stämmer inte. Skriv tio siffror, till exempel 556677-8899.')]);
            }

            return "16{$digits}";
        }

        if (strlen($digits) !== 12 || ! in_array(substr($digits, 0, 2), ['19', '20'], true) || ! Luhn::isValid(substr($digits, 2))) {
            throw ValidationException::withMessages([$field => __('Personnumret stämmer inte. Skriv tolv siffror med århundradet, till exempel 19121212-1212.')]);
        }

        return $digits;
    }

    /** A share in per cent with at most two decimals, as basis points. */
    private static function share(string $value, string $field): int
    {
        $value = str_replace([' ', '%'], '', trim($value));

        if (! preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $value, $parts)) {
            throw ValidationException::withMessages([$field => __('Skriv andelen i procent, till exempel 50 eller 33,33.')]);
        }

        $bp = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

        if ($bp < 1 || $bp > 10_000) {
            throw ValidationException::withMessages([$field => __('Andelen ska vara mellan 0,01 och 100 %.')]);
        }

        return $bp;
    }
}
