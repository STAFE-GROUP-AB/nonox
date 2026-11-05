<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use App\Domain\AnnualReport\AnnualAccounts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxPartner;
use App\Models\IncomeTaxReturn;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Inkomstdeklaration 4 of a handelsbolag or kommanditbolag from the books
 * (ADR-053): INK4R (balance sheet with the partners' equity and income
 * statement), INK4S (the tax adjustments, the surplus or deficit) and one
 * INK4DU per partner (share of the surplus or deficit, contributions and
 * withdrawals from the partner's BAS block). A handelsbolag pays no income
 * tax: periodiseringsfond, räntefördelning and egenavgifter belong to each
 * partner, who files N3A (a person) or N3B (a legal person) with their own
 * return. NONOX does not build N3A or N3B.
 *
 * The first page of INK4 cannot be sent by file transfer (Skatteverket); it
 * is only needed for särskild löneskatt, avkastningsskatt or fastighetsskatt,
 * and the page warns when the books show such an underlag.
 *
 * Contributions and withdrawals come from x7–x8 and x1–x3 of each partner's
 * BAS block unless a person enters them for the partner; other movements in
 * the block during the year are warned about, by account. 8105 is entered
 * per partner, only when the bolag used a regional nedsättning.
 *
 * TODO(legal-verify): the split of the surplus by share with the remainder
 * kronor to the last partner (IL 5 kap.).
 */
final class Ink4
{
    /** @param  Collection<int, IncomeTaxPartner>  $partners */
    public function __construct(
        public readonly Company $company,
        public readonly FiscalYear $fiscalYear,
        private readonly AnnualAccounts $accounts,
        private readonly ?IncomeTaxReturn $return,
        public readonly Collection $partners,
    ) {}

    /** @param  Collection<int, IncomeTaxPartner>  $partners */
    public static function for(Company $company, FiscalYear $fiscalYear, ?IncomeTaxReturn $return, Collection $partners): self
    {
        return new self($company, $fiscalYear, AnnualAccounts::for($fiscalYear), $return, $partners->sortBy('position')->values());
    }

    public function period(): string
    {
        return Periods::of($this->fiscalYear);
    }

    public function isVerifiedPeriod(): bool
    {
        return in_array($this->period(), Ink4Codes::VERIFIED_PERIODS, true);
    }

    /** 16 and the ten digits of the organisation number. */
    public function identity(): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $this->company->org_number);

        if (strlen($digits) !== 10) {
            throw ValidationException::withMessages(['org_number' => __('Bolagets organisationsnummer behövs för deklarationen.')]);
        }

        return "16{$digits}";
    }

    /** @return array<int, int|string> INK4R */
    public function ink4r(): array
    {
        $fields = $this->dates();

        foreach (Ink4Codes::ASSETS as $code => $ranges) {
            $fields[$code] = Ink2::kronor($this->closing($ranges));
        }

        foreach (Ink4Codes::LIABILITIES as $code => $ranges) {
            $fields[$code] = Ink2::kronor(-$this->closing($ranges));
        }

        $result = $this->resultKronor();
        $opening = Ink2::kronor(-$this->accounts->openingOf(Ink4Codes::EQUITY[0], Ink4Codes::EQUITY[1]));
        // The equity at year end with the year's result, booked to x9 or not.
        $closing = Ink2::kronor(-$this->accounts->closingOf(Ink4Codes::EQUITY[0], Ink4Codes::EQUITY[1]) - $this->accounts->movementOf(3000, 8999));
        $fields[Ink4Codes::OPENING_CAPITAL] = $opening;
        $fields[Ink4Codes::NET_CONTRIBUTIONS] = $closing - $opening - $result;
        $fields[$result >= 0 ? Ink4Codes::EQUITY_PROFIT : Ink4Codes::EQUITY_LOSS] = abs($result);
        $fields[Ink4Codes::CLOSING_CAPITAL] = $closing;

        foreach (Ink4Codes::INCOME as $line) {
            $amount = -$this->accounts->movementOfRanges($line['ranges'], $line['resale'] ?? null);
            $code = $amount >= 0 ? $line['income'] : $line['cost'];

            if ($code === null) {
                $code = $line['income'] ?? $line['cost'];
                $amount = $line['income'] === null ? -$amount : $amount;
                $fields[$code] = ($fields[$code] ?? 0) + Ink2::kronor($amount);

                continue;
            }

            $fields[$code] = ($fields[$code] ?? 0) + Ink2::kronor(abs($amount));
        }

        $fields[$result >= 0 ? Ink4Codes::PROFIT : Ink4Codes::LOSS] = abs($result);

        return self::withoutZeros($fields);
    }

    /** @return array<int, int|string> INK4S */
    public function ink4s(): array
    {
        $fields = $this->dates();
        $result = $this->resultKronor();
        $fields[$result >= 0 ? Ink4Codes::S_PROFIT : Ink4Codes::S_LOSS] = abs($result);

        foreach (array_keys(Ink4Codes::ADJUSTMENTS) as $code) {
            $fields[$code] = $this->entered($code);
        }

        $taxable = $this->taxableKronor();
        $fields[$taxable >= 0 ? Ink4Codes::S_SURPLUS : Ink4Codes::S_DEFICIT] = abs($taxable);

        foreach (array_keys(Ink4Codes::INFORMATION) as $code) {
            $fields[$code] = $this->valueOf($code);
        }

        $fields = self::withoutZeros($fields);

        if ($this->return?->consultant !== null) {
            $fields[$this->return->consultant ? Ink4Codes::CONSULTANT_YES : Ink4Codes::CONSULTANT_NO] = 'X';
        }

        if ($this->return?->audited !== null) {
            $fields[$this->return->audited ? Ink4Codes::AUDITED_YES : Ink4Codes::AUDITED_NO] = 'X';
        }

        return $fields;
    }

    /**
     * One INK4DU per partner: identity (8100) and the share of the surplus or
     * deficit, contributions and withdrawals.
     *
     * @return list<array{partner: IncomeTaxPartner, fields: array<int, int|string>}>
     */
    public function ink4du(): array
    {
        $taxable = $this->taxableKronor();
        $left = abs($taxable);
        $blocks = [];
        $last = $this->partners->count() - 1;

        foreach ($this->partners->values() as $index => $partner) {
            // Each share rounded down; the last partner takes what is left so the shares add up.
            $share = $index === $last ? $left : intdiv(abs($taxable) * $partner->share_bp, 10_000);
            $left -= $share;
            // + keeps the field codes as keys; spreading an int-keyed array would renumber them.
            $fields = $this->dates() + [
                Ink4Codes::DU_PARTNER => $partner->identity,
                $taxable >= 0 ? Ink4Codes::DU_SURPLUS : Ink4Codes::DU_DEFICIT => $share,
                Ink4Codes::DU_CONTRIBUTIONS => $partner->contributions_kronor ?? $this->contributions($partner->position),
                Ink4Codes::DU_WITHDRAWALS => $partner->withdrawals_kronor ?? $this->withdrawals($partner->position),
                Ink4Codes::DU_EMPLOYER_DEDUCTION => $partner->employer_deduction_kronor ?? 0,
            ];
            $blocks[] = ['partner' => $partner, 'fields' => self::withoutZeros($fields)];
        }

        return $blocks;
    }

    /** The book result in whole kronor (3000–8989). */
    public function resultKronor(): int
    {
        return Ink2::kronor(-$this->accounts->movementOf(3000, 8989));
    }

    public function taxableKronor(): int
    {
        $taxable = $this->resultKronor();

        foreach (Ink4Codes::ADJUSTMENTS as $code => [, $sign]) {
            $taxable += $sign * $this->entered($code);
        }

        return $taxable;
    }

    /** @return array<int, int> proposals from the books: 8022 from BAS 74xx */
    public function proposals(): array
    {
        return [Ink4Codes::S_PENSION_COSTS => max(Ink2::kronor($this->accounts->movementOf(Ink4Codes::PENSION_ACCOUNTS[0], Ink4Codes::PENSION_ACCOUNTS[1])), 0)];
    }

    /** The partner's withdrawals in the year, whole kronor: debit on x1–x3 of the block. */
    public function withdrawals(int $position): int
    {
        return $this->blockMovement($position, Ink4Codes::WITHDRAWALS);
    }

    /** The partner's contributions in the year, whole kronor: credit on x7–x8 of the block. */
    public function contributions(int $position): int
    {
        return -$this->blockMovement($position, Ink4Codes::CONTRIBUTIONS);
    }

    /** @return list<string> */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->partners->isEmpty()) {
            $warnings[] = __('Lägg in delägarna och deras andelar. Varje delägare behöver en egen bilaga INK4DU.');
        } elseif ($this->partners->sum('share_bp') !== 10_000) {
            $warnings[] = __('Delägarnas andelar blir :share %, inte 100 %. Rätta andelarna innan du laddar ner filerna.', ['share' => IncomeTaxForms::percent((int) $this->partners->sum('share_bp'))]);
        }

        if ($this->accounts->movementOf(Ink4Codes::TAX_ACCOUNTS[0], Ink4Codes::TAX_ACCOUNTS[1]) !== 0) {
            $warnings[] = __('Det finns bokningar på skattekontona 8900–8989. Ett handelsbolag betalar ingen inkomstskatt och de har ingen rad på INK4R; kontrollera dem.');
        }

        $pension = $this->valueOf(Ink4Codes::S_PENSION_COSTS);

        if ($pension > 0 || $this->accounts->movementOf(Ink4Codes::PROPERTY_TAX_ACCOUNTS[0], Ink4Codes::PROPERTY_TAX_ACCOUNTS[1]) !== 0) {
            $warnings[] = __('Bolaget behöver lämna första sidan av Inkomstdeklaration 4, med underlaget för särskild löneskatt på pensionskostnader eller för fastighetsskatt. Den kan inte skickas med filöverföring: skicka den på papper, eller lämna hela deklarationen i Skatteverkets e-tjänst. Ett deklarationsombud får inte skriva under en deklaration på papper.');
        }

        foreach ($this->partners as $partner) {
            $accounts = $this->otherBlockMovements($partner->position);

            if ($accounts !== []) {
                $warnings[] = __('Delägare :position (:name) har bokningar under året på :accounts, som inte räknas som uttag (x1–x3) eller tillskott (x7–x8). Är det uttag eller tillskott, fyll i delägarens uttag och tillskott själv.', ['position' => $partner->position, 'name' => $partner->name, 'accounts' => implode(', ', $accounts)]);
            }
        }

        $books = $this->withdrawalsAndContributions();
        $fields = $this->ink4r();

        if (($fields[Ink4Codes::NET_CONTRIBUTIONS] ?? 0) !== $books) {
            $warnings[] = __('Delägarnas nettouttag eller nettotillskott enligt eget kapital (:equity) skiljer sig från uttagen och tillskotten på delägarnas konton (:accounts). Kontrollera de andra bokningarna på eget kapital.', ['equity' => IncomeTaxForms::kronor((int) ($fields[Ink4Codes::NET_CONTRIBUTIONS] ?? 0)), 'accounts' => IncomeTaxForms::kronor($books)]);
        }

        return $warnings;
    }

    /** The net of every partner's contributions less withdrawals on BAS 2010–2049. */
    private function withdrawalsAndContributions(): int
    {
        $net = 0;

        for ($position = 1; $position <= Ink4Codes::MAX_PARTNERS_IN_BAS; $position++) {
            $net += $this->contributions($position) - $this->withdrawals($position);
        }

        return $net;
    }

    /**
     * The accounts in a partner's block with movements in the year that are
     * neither withdrawals (x1–x3) nor contributions (x7–x8). x9 holds the
     * year's result. x0 may be moved by the balances carried into it at the start of
     * the year (BAS practice: all of x1–x9, or x9 only); anything else is
     * listed.
     *
     * @return list<int>
     */
    private function otherBlockMovements(int $position): array
    {
        if ($position < 1 || $position > Ink4Codes::MAX_PARTNERS_IN_BAS) {
            return [];
        }

        $base = 2000 + 10 * $position;
        $listed = [];
        $moved = Ink2::kronor($this->accounts->movementOf($base, $base));
        $carried = [0, Ink2::kronor($this->accounts->openingOf($base + 1, $base + 9)), Ink2::kronor($this->accounts->openingOf($base + 9, $base + 9))];

        if (! in_array($moved, $carried, true)) {
            $listed[] = $base;
        }

        foreach ([4, 5, 6] as $digit) {
            if (Ink2::kronor($this->accounts->movementOf($base + $digit, $base + $digit)) !== 0) {
                $listed[] = $base + $digit;
            }
        }

        return $listed;
    }

    /** @param  array{0: int, 1: int}  $digits */
    private function blockMovement(int $position, array $digits): int
    {
        if ($position < 1 || $position > Ink4Codes::MAX_PARTNERS_IN_BAS) {
            return 0;
        }

        $base = 2000 + 10 * $position;

        return Ink2::kronor($this->accounts->movementOf($base + $digits[0], $base + $digits[1]));
    }

    public function entered(int $code): int
    {
        return (int) ($this->return->adjustments[$code] ?? 0);
    }

    /** Whether a person has entered the field, 0 included. */
    public function isEntered(int $code): bool
    {
        return array_key_exists($code, $this->return->adjustments ?? []);
    }

    private function valueOf(int $code): int
    {
        return $this->isEntered($code) ? $this->entered($code) : ($this->proposals()[$code] ?? 0);
    }

    /** @return array<int, string> */
    private function dates(): array
    {
        return [7011 => $this->fiscalYear->start_date->format('Ymd'), 7012 => $this->fiscalYear->end_date->format('Ymd')];
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private function closing(array $ranges): int
    {
        return array_sum(array_map(fn (array $range): int => $this->accounts->closingOf($range[0], $range[1]), $ranges));
    }

    /**
     * @param  array<int, int|string>  $fields
     * @return array<int, int|string>
     */
    private static function withoutZeros(array $fields): array
    {
        return array_filter($fields, fn (int|string $value): bool => $value !== 0);
    }
}
