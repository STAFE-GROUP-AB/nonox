<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Closing\ConfirmK2Conditions;
use App\Actions\Closing\YearEndClosing;
use App\Domain\AnnualReport\K3Eligibility;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Domain\Closing\ClosingAccounts;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Closing\CurrencyRevaluation;
use App\Domain\Closing\DividendLimit;
use App\Domain\Closing\K2Reconfirmation;
use App\Domain\Closing\TaxProposal;
use App\Domain\Closing\UnpaidInvoices;
use App\Domain\Closing\YearResult;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\ClosingCheck;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Models\K3Transition;
use App\Models\User;
use App\Models\YearEndEntry;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Bokslut: the year-end checklist, the closing vouchers, closing
 * the year and, in the next year, the disposition of the result.
 */
class ClosingPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar', except: 0)]
    public int $year = 0;

    public string $tax = '';

    public string $dispositionDate = '';

    public string $dividend = '';

    public string $openingReason = '';

    /** The reversal of old periodiseringsfonder in whole kronor; empty proposes what the law requires. */
    public string $reversal = '';

    /** This year's allocation to a periodiseringsfond in whole kronor. */
    public string $allocation = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage'), 403);

        if (FiscalYear::query()->whereKey($this->year)->doesntExist()) {
            // The earliest year still open is the one to close; otherwise the latest.
            $this->year = (int) (FiscalYear::query()->where('status', 'OPEN')->whereDate('end_date', '<', now()->toDateString())->orderBy('start_date')->value('id')
                ?? FiscalYear::query()->orderByDesc('start_date')->value('id'));
        }
    }

    /** Owner decision 16: the K2 conditions as amended by BFNAR 2025:2, confirmed once per company. */
    public function confirmK2Conditions(ConfirmK2Conditions $confirm): void
    {
        $this->attempt(function () use ($confirm): void {
            $confirm($this->user(), $this->fiscalYear());
            $this->toast(__('K2-villkoren är bekräftade. Du kan fortsätta med bokslutet.'));
        });
    }

    public function check(YearEndClosing $closing, string $key, bool $checked): void
    {
        $this->attempt(fn () => $closing->check($this->user(), $this->fiscalYear(), $key, $checked));
    }

    /** Confirms, with the reason, that opening balances deliberately differ from last year's closing balances. */
    public function confirmOpening(YearEndClosing $closing): void
    {
        $this->attempt(function () use ($closing): void {
            $closing->check($this->user(), $this->fiscalYear(), ClosingChecklist::OPENING, true, $this->openingReason);
            $this->reset('openingReason');
            $this->toast(__('Skillnaden är bekräftad som avsiktlig. Lämna skälet i en not i årsredovisningen.'));
        });
    }

    public function bookTax(YearEndClosing $closing): void
    {
        $this->attempt(function () use ($closing): void {
            $entry = $closing->bookTax($this->user(), $this->fiscalYear(), $this->tax);
            $this->reset('tax');
            $this->toast($entry->amount_ore->ore === 0 ? __('Noterat att året inte ger någon skatt.') : __('Skatten :amount är bokförd.', ['amount' => $entry->amount_ore->format()]));
        });
    }

    /** Books the reviewed tax proposal; the amount shown is the amount confirmed (ADR-056). */
    public function confirmTaxProposal(YearEndClosing $closing, int $taxOre): void
    {
        $this->attempt(function () use ($closing, $taxOre): void {
            $entry = $closing->confirmTaxProposal($this->user(), $this->fiscalYear(), $this->reversal, $this->allocation, $taxOre);
            $this->reset('reversal', 'allocation');
            $this->toast($entry->amount_ore->ore === 0 ? __('Beräkningen är bekräftad. Året ger ingen skatt.') : __('Beräkningen är bekräftad och skatten :amount är bokförd.', ['amount' => $entry->amount_ore->format()]));
        });
    }

    /** Whether the company is a privatbostadsföretag or an investmentföretag; only "neither" gives a tax proposal. */
    public function answerTaxStatus(YearEndClosing $closing, string $status): void
    {
        $this->attempt(function () use ($closing, $status): void {
            $closing->answerTaxStatus($this->user(), $this->fiscalYear(), $status);
            $this->toast(__('Svaret är sparat.'));
        });
    }

    /** Fills in the largest allocation the law allows. */
    public function useMaxAllocation(): void
    {
        $this->allocation = (string) ($this->proposal()?->maxAllocationKronor() ?? 0);
    }

    public function bookUnpaidInvoices(YearEndClosing $closing): void
    {
        $this->attempt(function () use ($closing): void {
            $entries = collect($closing->bookUnpaidInvoices($this->user(), $this->fiscalYear()))->keyBy('type');
            $receivables = $entries->get(YearEndEntry::UNPAID_RECEIVABLES)?->amount_ore->format() ?? Money::zero()->format();
            $payables = $entries->get(YearEndEntry::UNPAID_PAYABLES)?->amount_ore->format() ?? Money::zero()->format();
            $this->toast(__('De obetalda fakturorna är bokförda: kundfordringar :receivables och leverantörsskulder :payables. De återförs första dagen i nästa år.', ['receivables' => $receivables, 'payables' => $payables]));
        });
    }

    public function bookCurrencyRevaluation(YearEndClosing $closing): void
    {
        $this->attempt(function () use ($closing): void {
            $entry = $closing->bookCurrencyRevaluation($this->user(), $this->fiscalYear());
            $this->toast($entry->amount_ore->ore >= 0
                ? __('Omvärderingen är bokförd: kursvinst :amount. Den återförs första dagen i nästa år.', ['amount' => $entry->amount_ore->format()])
                : __('Omvärderingen är bokförd: kursförlust :amount. Den återförs första dagen i nästa år.', ['amount' => $entry->amount_ore->negated()->format()]));
        });
    }

    public function bookResult(YearEndClosing $closing): void
    {
        $this->attempt(function () use ($closing): void {
            $entry = $closing->bookResult($this->user(), $this->fiscalYear());
            $this->toast(__('Årets resultat, :amount, är bokat mot eget kapital.', ['amount' => $entry->amount_ore->format()]));
        });
    }

    public function close(YearEndClosing $closing): void
    {
        $this->attempt(function () use ($closing): void {
            $year = $closing->close($this->user(), $this->fiscalYear());
            $this->toast(__('Räkenskapsåret :year är stängt och balanserna är förda till nästa år.', ['year' => $year->label()]));
        });
    }

    public function dispose(YearEndClosing $closing): void
    {
        $this->attempt(function () use ($closing): void {
            $closing->dispose($this->user(), $this->fiscalYear(), $this->dispositionDate, $this->dividend);
            $this->reset('dispositionDate', 'dividend');
            $this->toast(__('Resultatdispositionen är bokförd.'));
        });
    }

    public function render(): View
    {
        $fiscalYear = $this->fiscalYear();
        $entries = YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->orderBy('id')->get();
        $items = ClosingChecklist::for($this->company(), $fiscalYear);
        $next = FiscalYear::query()->whereDate('start_date', $fiscalYear->end_date->addDay()->toDateString())->first();
        // The first K3 year after K2 offers Övergång till K3 (S1-P12); read only for a K3 year.
        $k3TransitionOffered = $fiscalYear->reporting_framework === ReportingFramework::K3 && K3TransitionProposal::refusals($this->company(), $fiscalYear) === [];

        if ($this->dispositionDate === '' && $next !== null) {
            $this->dispositionDate = $next->start_date->addMonthsNoOverflow(5)->endOfMonth()->min($next->end_date)->toDateString();
        }

        return view('livewire.accounting.closing', [
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'fiscalYear' => $fiscalYear,
            'items' => $items,
            'k2Conditions' => K2Reconfirmation::isPending($this->company(), $fiscalYear) ? K2Reconfirmation::conditions($this->company()) : null,
            // Shown next to the K2 confirmation (ADR-059 S1-P11); computed only while the confirmation is pending.
            'k2Eligibility' => K2Reconfirmation::isPending($this->company(), $fiscalYear) ? K3Eligibility::forYear($fiscalYear) : null,
            'company' => $this->company(),
            'complete' => ClosingChecklist::isComplete($items),
            'result' => YearResult::of($fiscalYear),
            'taxEntry' => $entries->firstWhere('type', YearEndEntry::TAX),
            'deferredTaxEntry' => $entries->firstWhere('type', YearEndEntry::DEFERRED_TAX),
            'k3TransitionOffered' => $k3TransitionOffered,
            'k3Transition' => $k3TransitionOffered ? K3Transition::query()->where('fiscal_year_id', $fiscalYear->id)->first() : null,
            'unpaidEntries' => $entries->whereIn('type', [YearEndEntry::UNPAID_RECEIVABLES, YearEndEntry::UNPAID_PAYABLES])->keyBy('type'),
            'unpaid' => $fiscalYear->isOpen() && $entries->whereIn('type', [YearEndEntry::UNPAID_RECEIVABLES, YearEndEntry::UNPAID_PAYABLES])->isEmpty()
                && ClosingCheck::query()->where('fiscal_year_id', $fiscalYear->id)->where('key', 'unpaid')->doesntExist()
                ? UnpaidInvoices::for($this->company(), $fiscalYear, withRows: false)
                : null,
            'revaluationEntry' => $entries->firstWhere('type', YearEndEntry::CURRENCY_REVALUATION),
            'revaluation' => $fiscalYear->isOpen() && $entries->firstWhere('type', YearEndEntry::CURRENCY_REVALUATION) === null
                ? CurrencyRevaluation::for($this->company(), $fiscalYear)
                : null,
            'disposition' => $entries->firstWhere('type', YearEndEntry::DISPOSITION),
            // The room for a dividend, shown by the field (ABL 17 kap. 3 §).
            'freeEquity' => ! $fiscalYear->isOpen() && $next !== null && ! ClosingAccounts::isOwnerTaxed($this->company()) && $entries->firstWhere('type', YearEndEntry::DISPOSITION) === null
                ? DividendLimit::freeEquityOre($fiscalYear)
                : null,
            'ownerTaxed' => ClosingAccounts::isOwnerTaxed($this->company()),
            'taxProposal' => $fiscalYear->isOpen() && $entries->firstWhere('type', YearEndEntry::TAX) === null ? $this->proposal() : null,
            'proposalApplies' => TaxProposal::appliesTo($this->company()),
            'resultAccount' => ClosingAccounts::resultNumber($this->company()),
            'next' => $next,
            'canClose' => $this->user()->hasTeamPermission($this->company(), 'periods:lock'),
        ]);
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /** The tax proposal with what the person has typed; an amount that cannot be read counts as 0 until it is confirmed. */
    private function proposal(): ?TaxProposal
    {
        $company = $this->company();

        if (! TaxProposal::appliesTo($company)) {
            return null;
        }

        $fiscalYear = $this->fiscalYear();
        $return = IncomeTaxReturn::query()->where('fiscal_year_id', $fiscalYear->id)->where('form', IncomeTaxReturn::INK2)->first();

        return TaxProposal::for($company, $fiscalYear, $return, $this->reversal === '' ? null : self::kronorOrZero($this->reversal), self::kronorOrZero($this->allocation));
    }

    private static function kronorOrZero(string $value): int
    {
        try {
            return trim($value) === '' ? 0 : max(intdiv(Money::parse($value)->ore, 100), 0);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    private function fiscalYear(): FiscalYear
    {
        return FiscalYear::query()->findOrFail($this->year);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
