<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use App\Domain\Company\LegalForm;
use App\Models\Company;
use App\Models\K10Owner;
use App\Models\K10Year;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * One owner's K10 worksheet (ADR-062): the proposals from the books, what a
 * person entered in their place, the calculation, and why it cannot be made
 * when it cannot. A proposal, never a filing: nothing is booked or sent.
 */
final class K10Sheet
{
    /** @var list<string> */
    private array $blockers = [];

    /** @var list<string> */
    private array $warnings = [];

    private ?K10 $k10 = null;

    private ?K10Input $input = null;

    /** @var array<string, string> where each input came from, by key */
    private array $origins = [];

    /** @param  Collection<int, K10Owner>  $owners  the company's owners for the year, keyed by id */
    private function __construct(
        public readonly Company $company,
        public readonly int $incomeYear,
        public readonly ?K10Parameters $parameters,
        public readonly ?K10Year $year,
        public readonly K10Owner $owner,
        public readonly Collection $owners,
        public readonly K10Books $books,
    ) {
        $this->build();
    }

    /** @param  Collection<int, K10Owner>  $owners */
    public static function for(Company $company, ?K10Year $year, K10Owner $owner, Collection $owners, K10Books $books): self
    {
        return new self($company, $owner->income_year, K10Parameters::for($owner->income_year), $year, $owner, $owners->keyBy('id'), $books);
    }

    public function k10(): ?K10
    {
        return $this->k10;
    }

    public function input(): ?K10Input
    {
        return $this->input;
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function origin(string $key): string
    {
        return $this->origins[$key] ?? '';
    }

    public function spouse(): ?K10Owner
    {
        return $this->owner->spouse_owner_id === null ? null : $this->owners->get($this->owner->spouse_owner_id);
    }

    public function period(): string
    {
        return $this->parameters === null ? "{$this->incomeYear}P4" : $this->parameters->period;
    }

    /** Whether an SRU file can be made: a calculation, a checked field table and the owner's personnummer. */
    public function canExportSru(): bool
    {
        return $this->k10 !== null && K10Codes::isVerifiedPeriod($this->period()) && filled($this->owner->identity);
    }

    private function build(): void
    {
        $owner = $this->owner;
        $previous = $this->incomeYear - 1;
        $total = $this->year?->shares_total;

        if ($this->company->legal_form !== LegalForm::Ab) {
            $this->blockers[] = __('K10 lämnas av delägare i aktiebolag. NONOX tar bara fram K10-underlag för aktiebolag.');
        }

        if ($this->parameters === null) {
            $this->blockers[] = __('Beloppen och procentsatserna för inkomstår :year finns inte i NONOX, så K10 kan inte räknas. Använd Skatteverkets e-tjänst.', ['year' => $this->incomeYear]);
        }

        if ($this->year?->qualified_confirmed !== true) {
            $this->blockers[] = __('Bekräfta först att företaget är ett fåmansföretag och att aktierna är kvalificerade. Det bedömer du själv (IL 56 kap. 2 § och 57 kap. 4 §).');
        }

        if ($total === null || $total < 1) {
            $this->blockers[] = __('Fyll i hur många aktier företaget hade vid årets ingång.');
        } elseif ($owner->shares_owned > $total || $this->owners->sum('shares_owned') > $total) {
            $this->blockers[] = __('Ägarnas aktier är fler än företagets aktier. Kontrollera antalen.');
        }

        if ($owner->acquired_on !== null && $owner->acquired_on->year >= $this->incomeYear) {
            $this->blockers[] = __('Årets gränsbelopp räknas för den som ägde aktierna vid årets ingång (IL 57 kap. 11 §). Aktier som köpts under :year ger inget gränsbelopp för :year.', ['year' => $this->incomeYear]);
        }

        if ($owner->transferred_shares) {
            $this->blockers[] = __('Aktier som sålts eller getts bort under året räknar NONOX inte på (punkt 1.5, 2.5 och avsnitt C). Fyll i K10 i Skatteverkets e-tjänst.');
        }

        if ($owner->topped_up_previous_year) {
            $this->blockers[] = __('Ägaren hade aktier hela :year och köpte fler under året. Då ska löneunderlaget räknas separat för de olika aktierna (IL 57 kap. 16 a §, punkt 4.1–4.4 och 4.5–4.8). Det räknar NONOX inte på; fyll i K10 i Skatteverkets e-tjänst.', ['year' => $previous]);
        }

        $spouse = $this->spouse();
        $acquired = $owner->acquired_on !== null && $owner->acquired_on->year === $previous && $owner->acquired_on->greaterThan("{$previous}-01-01");

        if ($spouse !== null) {
            $spouseAcquired = $spouse->acquired_on !== null && $spouse->acquired_on->year === $previous && $spouse->acquired_on->greaterThan("{$previous}-01-01");

            if ($spouseAcquired !== $acquired || ($acquired && ! $spouse->acquired_on?->equalTo($owner->acquired_on))) {
                $this->blockers[] = __('Makarna har köpt sina aktier vid olika tidpunkter. Den gemensamma beräkningen räknar NONOX inte på; fyll i K10 i Skatteverkets e-tjänst.');
            }
        }

        if ($this->blockers !== [] || $this->parameters === null || $total === null) {
            return;
        }

        $this->input = $this->inputs($total, $spouse, $acquired);
        $this->k10 = K10::calculate($this->parameters, $this->input);
        $this->warn($acquired);
    }

    private function inputs(int $total, ?K10Owner $spouse, bool $acquired): K10Input
    {
        $owner = $this->owner;
        $from = $acquired ? $owner->acquired_on : null;

        if ($owner->company_pay_kronor !== null) {
            $companyPay = $owner->company_pay_kronor;
            $this->origins['company_pay'] = 'entered';
        } else {
            $subsidy = $acquired ? 0 : (int) $this->year?->wage_subsidy_kronor;
            $companyPay = max(0, $this->books->companyPayKronor($from) - $subsidy);
            $this->origins['company_pay'] = $this->books->source;
        }

        $ownPay = $this->ownPay($owner);
        $capBasis = max($ownPay, $spouse === null ? 0 : $this->ownPay($spouse, record: false), (int) $owner->related_pay_kronor);

        if ($owner->dividend_kronor !== null) {
            $dividend = $owner->dividend_kronor;
            $this->origins['dividend'] = 'entered';
        } else {
            $companyDividend = $this->year->dividend_total_kronor ?? $this->books->dividendDecidedKronor();
            $dividend = BigInteger::of($companyDividend)->multipliedBy($owner->shares_owned)->dividedBy($total, RoundingMode::Floor)->toInt();
            $this->origins['dividend'] = $this->year?->dividend_total_kronor !== null ? 'entered_total' : 'ledger';
        }

        return new K10Input(
            sharesOwned: $owner->shares_owned,
            sharesTotal: $total,
            otherHoldingsBp: $owner->other_holdings_bp,
            spouseShares: $spouse->shares_owned ?? 0,
            acquiredDuringPreviousYear: $acquired,
            companyPayKronor: $companyPay,
            subsidiaryPayKronor: (int) $owner->subsidiary_pay_kronor,
            capBasisKronor: $capBasis,
            costBasisKronor: (int) $owner->cost_basis_kronor,
            savedSpaceKronor: (int) $owner->saved_space_kronor,
            dividendKronor: $dividend,
        );
    }

    private function ownPay(K10Owner $owner, bool $record = true): int
    {
        $origin = 'none';
        $pay = 0;

        if ($owner->own_pay_kronor !== null) {
            [$pay, $origin] = [$owner->own_pay_kronor, 'entered'];
        } elseif (($fromPayroll = $this->books->employeePayKronor($owner->employee_id)) !== null) {
            [$pay, $origin] = [$fromPayroll, K10Books::SOURCE_PAYROLL];
        }

        if ($record) {
            $this->origins['own_pay'] = $origin;
        }

        return $pay;
    }

    private function warn(bool $acquired): void
    {
        $k10 = $this->k10;
        $previous = $this->incomeYear - 1;

        if ($k10 === null) {
            return;
        }

        $this->warnings = $this->books->warnings();

        if ($this->origins['own_pay'] === 'none' && $this->spouse() === null && (int) $this->owner->related_pay_kronor === 0) {
            $this->warnings[] = __('Ingen kontant ersättning till dig eller en närstående under :year är angiven. Det lönebaserade utrymmet får vara högst 50 gånger den ersättningen och blir då 0 kr (IL 57 kap. 16 §). Fyll i din lön för :year om du hade lön från företaget.', ['year' => $previous]);
        }

        if ($k10->isSalarySpaceCapped()) {
            $this->warnings[] = __('Det lönebaserade utrymmet är begränsat till 50 gånger den högsta kontanta ersättningen till dig eller en närstående under :year.', ['year' => $previous]);
        }

        if ($acquired && $this->year?->wage_subsidy_kronor !== null && $this->owner->company_pay_kronor === null) {
            $this->warnings[] = __('Du köpte aktierna under :year. Lön som täcks av statliga bidrag är inte dragen från lönerna för den del av året du ägde aktierna; fyll i lönebeloppet själv.', ['year' => $previous]);
        }

        if ($this->owner->other_holdings_bp === null) {
            $this->warnings[] = __('Äger du kvalificerade aktier i fler fåmansföretag får du sammanlagt bara ett grundbelopp (IL 57 kap. 11 a §). Fyll i dina ägarandelar i de andra företagen, så fördelar NONOX grundbeloppet.');
        }

        if ($k10->hasDividend() && $this->origins['dividend'] === 'ledger') {
            $this->warnings[] = __('Utdelningen är beslutad utdelning bokförd på 2898 under :year, fördelad efter ditt antal aktier. Beskattas utdelningen ett annat år, eller finns flera aktieslag, fyll i din utdelning själv.', ['year' => $this->incomeYear]);
        }

        if ($k10->isEmploymentIncomeCapped()) {
            $this->warnings[] = __('Utdelningen som beskattas i tjänst är begränsad till 90 inkomstbasbelopp. Gränsen gäller dig och närstående tillsammans (IL 57 kap. 20 a §); kontrollera vad de har tagit upp.');
        }
    }
}
