<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\IncomeTax\BusinessIncomeTaxReturns;
use App\Actions\IncomeTax\IncomeTaxReturns;
use App\Domain\IncomeTax\IncomeTaxForms;
use App\Domain\IncomeTax\Ink2;
use App\Domain\IncomeTax\Ink2Codes;
use App\Domain\IncomeTax\Ink4;
use App\Domain\IncomeTax\Ink4Codes;
use App\Domain\IncomeTax\Ne;
use App\Domain\IncomeTax\NeCodes;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxPartner;
use App\Models\IncomeTaxReturn;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Inkomstdeklaration: the return of the company's legal form
 * from the books (ADR-010, ADR-053). INK2 for a limited company or an
 * ekonomisk förening, the NE-bilaga for a sole trader, Inkomstdeklaration 4
 * for a handelsbolag or kommanditbolag; what a person adds and confirms,
 * and the SRU files for Skatteverket.
 */
class IncomeTaxPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar', except: 0)]
    public int $year = 0;

    /** @var array<int, string> kronor by field code, as typed */
    public array $adjustments = [];

    /** @var array<string, mixed> NE: what the owner confirms for the calculations */
    public array $inputs = [];

    /** NE: the owner's century of birth, 19 or 20, for the personnummer and the egenavgifter. */
    public string $century = '';

    /**
     * INK4 partners, as typed. A person's personnummer never goes to the
     * browser: `identity` stays empty and `masked` shows the last four
     * digits; saving keeps the stored number (by `ref`) unless a new one is
     * typed. An organisationsnummer is not personal data and is shown.
     *
     * @var array<int, array{ref: string, masked: string, position: string, name: string, identity: string, legal_person: bool, share: string, contributions: string, withdrawals: string, employer_deduction: string}>
     */
    public array $partners = [];

    /** The partners shown are last year's, not yet saved for this year. */
    public bool $partnersCopied = false;

    public string $consultant = '';

    public string $audited = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage'), 403);

        if (FiscalYear::query()->whereKey($this->year)->doesntExist()) {
            $this->year = (int) (FiscalYear::query()->whereDate('end_date', '<', now()->toDateString())->orderByDesc('start_date')->value('id')
                ?? FiscalYear::query()->orderByDesc('start_date')->value('id'));
        }

        $this->loadForm();
    }

    public function updatedYear(): void
    {
        $this->loadForm();
    }

    public function save(IncomeTaxReturns $returns, BusinessIncomeTaxReturns $business): void
    {
        $fiscalYear = FiscalYear::query()->findOrFail($this->year);
        $consultant = $this->consultant === '' ? null : $this->consultant === '1';
        $audited = $this->audited === '' ? null : $this->audited === '1';

        match ($this->form()) {
            IncomeTaxReturn::INK2 => $returns->save($this->user(), $fiscalYear, $this->adjustments, $consultant, $audited),
            IncomeTaxReturn::NE => $business->saveNe($this->user(), $fiscalYear, $this->adjustments, $this->inputs, $this->century === '' ? null : $this->century, $consultant),
            IncomeTaxReturn::INK4 => $business->saveInk4($this->user(), $fiscalYear, $this->adjustments, $consultant, $audited),
            default => abort(404),
        };

        $this->toast(__('Deklarationsuppgifterna är sparade.'));
    }

    public function savePartners(BusinessIncomeTaxReturns $business): void
    {
        $business->savePartners($this->user(), FiscalYear::query()->findOrFail($this->year), $this->partners);
        $this->loadForm();
        $this->toast(__('Delägarna är sparade.'));
    }

    public function addPartner(): void
    {
        $used = array_map(fn (array $partner): int => (int) $partner['position'], $this->partners);
        $next = 1;

        while (in_array($next, $used, true)) {
            $next++;
        }

        $this->partners[] = ['ref' => '', 'masked' => '', 'position' => (string) $next, 'name' => '', 'identity' => '', 'legal_person' => false, 'share' => '', 'contributions' => '', 'withdrawals' => '', 'employer_deduction' => ''];
    }

    public function removePartner(int $index): void
    {
        unset($this->partners[$index]);
        $this->partners = array_values($this->partners);
    }

    public function render(): View
    {
        $fiscalYear = FiscalYear::query()->findOrFail($this->year);
        $company = $this->company();
        $form = $this->form();
        $return = $form === null ? null : IncomeTaxReturn::query()->where('fiscal_year_id', $fiscalYear->id)->where('form', $form)->first();
        $data = [
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'fiscalYear' => $fiscalYear,
            'company' => $company,
            'form' => $form,
        ];

        return match ($form) {
            IncomeTaxReturn::INK2 => view('livewire.accounting.income-tax', $data + self::ink2(Ink2::for($company, $fiscalYear, $return))),
            IncomeTaxReturn::NE => view('livewire.accounting.income-tax', $data + ['ne' => Ne::for($company, $fiscalYear, $return), 'neLabels' => NeCodes::LABELS, 'neAdjustments' => NeCodes::ADJUSTMENTS, 'neInformation' => NeCodes::INFORMATION]),
            IncomeTaxReturn::INK4 => view('livewire.accounting.income-tax', $data + [
                'ink4' => Ink4::for($company, $fiscalYear, $return, IncomeTaxPartner::query()->where('fiscal_year_id', $fiscalYear->id)->get()),
                'ink4Labels' => Ink4Codes::LABELS,
                'ink4Adjustments' => Ink4Codes::ADJUSTMENTS,
                'ink4Information' => Ink4Codes::INFORMATION,
            ]),
            default => view('livewire.accounting.income-tax', $data),
        };
    }

    /** @return array<string, mixed> */
    private static function ink2(Ink2 $ink2): array
    {
        return [
            'ink2' => $ink2,
            'forms' => ['INK2' => $ink2->ink2(), 'INK2R' => $ink2->ink2r(), 'INK2S' => $ink2->ink2s()],
            'fieldLabels' => Ink2Codes::enteredLabels(),
            'proposals' => $ink2->proposals(),
            'labels' => Ink2Codes::LABELS,
        ];
    }

    /** The company's return: INK2, NE or INK4, or null for a form NONOX does not prepare (ideell förening, stiftelse). */
    private function form(): ?string
    {
        return IncomeTaxForms::for($this->company());
    }

    private function loadForm(): void
    {
        $form = $this->form();
        $return = $form === null ? null : IncomeTaxReturn::query()->where('fiscal_year_id', $this->year)->where('form', $form)->first();
        $codes = match ($form) {
            IncomeTaxReturn::INK2 => array_keys(Ink2Codes::enteredLabels()),
            IncomeTaxReturn::NE => [...array_keys(NeCodes::ADJUSTMENTS), ...array_keys(NeCodes::INFORMATION), NeCodes::RESERVE_ALLOCATION, NeCodes::EXPANSION_INCREASE, NeCodes::EXPANSION_DECREASE, NeCodes::SELF_EMPLOYMENT_DEDUCTION],
            IncomeTaxReturn::INK4 => [...array_keys(Ink4Codes::ADJUSTMENTS), ...array_keys(Ink4Codes::INFORMATION)],
            default => [],
        };
        $this->adjustments = [];

        foreach ($codes as $code) {
            $amount = $return->adjustments[$code] ?? null;
            $this->adjustments[$code] = $amount === null ? '' : (string) $amount;
        }

        $inputs = $return->inputs ?? [];
        $this->inputs = [
            'activity' => (string) ($inputs['activity'] ?? ''),
            'passive' => (bool) ($inputs['passive'] ?? false),
            'outside_eea' => (bool) ($inputs['outside_eea'] ?? false),
            'leased_car' => (bool) ($inputs['leased_car'] ?? false),
            'first_year' => (bool) ($inputs['first_year'] ?? false),
            'full_pension' => (bool) ($inputs['full_pension'] ?? false),
            'benefit_accounts' => is_array($inputs['benefit_accounts'] ?? null) ? implode(', ', $inputs['benefit_accounts']) : '',
        ];

        foreach (['saved_allocation_opening', 'capital_base', 'positive_allocation', 'expansion_capital_base', 'expansion_fund_opening'] as $key) {
            $this->inputs[$key] = isset($inputs[$key]) && is_int($inputs[$key]) ? (string) $inputs[$key] : '';
        }

        // A new NE starts from the fördelningsbelopp saved on last year's (8011).
        if ($form === IncomeTaxReturn::NE && $return === null) {
            $saved = Ne::savedFromPreviousYear($this->company(), FiscalYear::query()->findOrFail($this->year));
            $this->inputs['saved_allocation_opening'] = $saved === null || $saved === 0 ? '' : (string) $saved;
        }

        $this->century = (string) ($return->personal['century'] ?? '');
        $this->consultant = $return?->consultant === null ? '' : ($return->consultant ? '1' : '0');
        $this->audited = $return?->audited === null ? '' : ($return->audited ? '1' : '0');
        $this->partners = [];
        $this->partnersCopied = false;

        if ($form === IncomeTaxReturn::INK4) {
            $saved = IncomeTaxPartner::query()->where('fiscal_year_id', $this->year)->orderBy('position')->get();

            if ($saved->isEmpty()) {
                // A new year starts from the partners of the year before.
                $previous = FiscalYear::query()->whereKeyNot($this->year)->whereDate('end_date', '<', (string) FiscalYear::query()->whereKey($this->year)->value('start_date'))->orderByDesc('end_date')->value('id');
                $saved = $previous === null ? $saved : IncomeTaxPartner::query()->where('fiscal_year_id', $previous)->orderBy('position')->get();
                $this->partnersCopied = $saved->isNotEmpty();
            }

            $this->partners = $saved->map(fn (IncomeTaxPartner $partner): array => [
                'ref' => (string) $partner->id,
                'masked' => $partner->legal_person ? '' : '••••••••-'.substr($partner->identity, -4),
                'position' => (string) $partner->position,
                'name' => $partner->name,
                'identity' => $partner->legal_person ? substr($partner->identity, 2) : '',
                'legal_person' => $partner->legal_person,
                'share' => rtrim(rtrim(IncomeTaxForms::percent($partner->share_bp), '0'), ','),
                'contributions' => $partner->contributions_kronor === null ? '' : (string) $partner->contributions_kronor,
                'withdrawals' => $partner->withdrawals_kronor === null ? '' : (string) $partner->withdrawals_kronor,
                'employer_deduction' => $partner->employer_deduction_kronor === null ? '' : (string) $partner->employer_deduction_kronor,
            ])->values()->all();
        }
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
