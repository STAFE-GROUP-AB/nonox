<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\IncomeTax\K10Worksheets;
use App\Domain\Company\LegalForm;
use App\Domain\IncomeTax\K10Books;
use App\Domain\IncomeTax\K10Codes;
use App\Domain\IncomeTax\K10Parameters;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\K10Owner;
use App\Models\K10Year;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → K10-underlag (Beta, ADR-062): each owner's K10 for an
 * inkomstår from the books and what the owner enters. A worksheet, never a
 * filing: the owner files K10 with their own Inkomstdeklaration 1.
 */
class K10Page extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar', except: 0)]
    public int $incomeYear = 0;

    /** @var array{shares_total: string, wage_subsidy: string, dividend_total: string, qualified_confirmed: bool} */
    public array $yearForm = ['shares_total' => '', 'wage_subsidy' => '', 'dividend_total' => '', 'qualified_confirmed' => false];

    /** The owner being edited: 0 for a new one, null when the form is closed. */
    public ?int $editing = null;

    /**
     * The owner form. The personnummer never goes to the browser: `identity`
     * is empty and `masked` shows the last four digits; saving keeps the
     * stored number unless a new one is typed.
     *
     * @var array<string, mixed>
     */
    public array $ownerForm = [];

    public function mount(): void
    {
        $this->authorizeAccess();

        $years = K10Parameters::years();

        if (! in_array($this->incomeYear, $years, true)) {
            $this->incomeYear = $years[0] ?? (int) now()->year;
        }

        $this->loadYear();
    }

    public function updatedIncomeYear(): void
    {
        $this->editing = null;
        $this->loadYear();
    }

    public function saveYear(K10Worksheets $worksheets): void
    {
        $worksheets->saveYear($this->user(), $this->company(), $this->incomeYear, $this->yearForm);
        $this->toast(__('Uppgifterna för året är sparade.'));
    }

    public function newOwner(): void
    {
        $this->editing = 0;
        $this->ownerForm = self::emptyOwner();
    }

    public function editOwner(int $ownerId): void
    {
        $this->authorizeAccess();
        $owner = K10Owner::query()->where('income_year', $this->incomeYear)->findOrFail($ownerId);
        $this->editing = $owner->id;
        $this->ownerForm = [
            'name' => $owner->name,
            'identity' => '',
            'masked' => $owner->maskedIdentity(),
            'remove_identity' => false,
            'shares_owned' => (string) $owner->shares_owned,
            'employee_id' => $owner->employee_id === null ? '' : (string) $owner->employee_id,
            'spouse_owner_id' => $owner->spouse_owner_id === null ? '' : (string) $owner->spouse_owner_id,
            'acquired_on' => $owner->acquired_on?->toDateString() ?? '',
            'other_holdings' => $owner->other_holdings_bp === null ? '' : rtrim(rtrim(number_format($owner->other_holdings_bp / 100, 2, ',', ''), '0'), ','),
            'transferred_shares' => $owner->transferred_shares,
            'topped_up_previous_year' => $owner->topped_up_previous_year,
            'saved_space_carried' => $owner->saved_space_carried,
        ];

        foreach (['cost_basis', 'saved_space', 'own_pay', 'related_pay', 'company_pay', 'subsidiary_pay', 'dividend'] as $key) {
            $value = $owner->{"{$key}_kronor"};
            $this->ownerForm[$key] = $value === null ? '' : (string) $value;
        }
    }

    public function cancelOwner(): void
    {
        $this->editing = null;
        $this->ownerForm = [];
    }

    public function saveOwner(K10Worksheets $worksheets): void
    {
        $input = $this->ownerForm;

        // Linking a payroll employee shows that person's pay: only for those who may see payroll.
        if (! $this->canSeePayroll()) {
            $stored = $this->editing ? K10Owner::query()->find($this->editing)?->employee_id : null;
            $input['employee_id'] = $stored === null ? '' : (string) $stored;
        }

        $worksheets->saveOwner($this->user(), $this->company(), $this->incomeYear, $input, $this->editing ?: null);
        $this->cancelOwner();
        $this->toast(__('Ägaren är sparad.'));
    }

    public function deleteOwner(int $ownerId, K10Worksheets $worksheets): void
    {
        $owner = K10Owner::query()->where('income_year', $this->incomeYear)->findOrFail($ownerId);
        $worksheets->deleteOwner($this->user(), $this->company(), $owner);
        $this->cancelOwner();
        $this->toast(__('Ägaren och K10-underlaget är borttagna.'));
    }

    public function copyOwners(K10Worksheets $worksheets): void
    {
        $count = $worksheets->copyFromPreviousYear($this->user(), $this->company(), $this->incomeYear);
        $this->loadYear();
        $this->toast(__('Ägarna från förra året är hämtade (:count). Kontrollera uppgifterna.', ['count' => $count]));
    }

    public function render(K10Worksheets $worksheets): View
    {
        $this->authorizeAccess();
        $company = $this->company();
        $applies = $company->legal_form === LegalForm::Ab && K10Parameters::for($this->incomeYear) !== null;
        $sheets = $applies ? $worksheets->sheets($this->user(), $company, $this->incomeYear) : collect();
        $owners = $sheets->map(fn ($sheet): K10Owner => $sheet->owner);

        return view('livewire.accounting.k10-page', [
            'company' => $company,
            'applies' => $applies,
            'isAb' => $company->legal_form === LegalForm::Ab,
            'years' => K10Parameters::years(),
            'sheets' => $sheets,
            'books' => $sheets->first()->books ?? ($applies ? K10Books::for($company, $this->incomeYear) : null),
            'owners' => $owners,
            'labels' => K10Codes::LABELS,
            'employees' => $applies && $this->canSeePayroll() ? Employee::query()->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']) : collect(),
            'canCopy' => $applies && $owners->isEmpty() && K10Owner::query()->where('income_year', $this->incomeYear - 1)->exists(),
        ]);
    }

    private function loadYear(): void
    {
        $year = K10Year::query()->where('income_year', $this->incomeYear)->first();
        $this->yearForm = [
            'shares_total' => $year?->shares_total === null ? '' : (string) $year->shares_total,
            'wage_subsidy' => $year?->wage_subsidy_kronor === null ? '' : (string) $year->wage_subsidy_kronor,
            'dividend_total' => $year?->dividend_total_kronor === null ? '' : (string) $year->dividend_total_kronor,
            'qualified_confirmed' => (bool) $year?->qualified_confirmed,
        ];
    }

    /** Every request, not only the first: a role can change while the page is open. */
    private function authorizeAccess(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage'), 403);
    }

    private function canSeePayroll(): bool
    {
        return app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $this->company())
            && $this->user()->hasTeamPermission($this->company(), 'payroll:view');
    }

    /** @return array<string, mixed> */
    private static function emptyOwner(): array
    {
        return [
            'name' => '', 'identity' => '', 'masked' => '', 'remove_identity' => false, 'shares_owned' => '', 'employee_id' => '', 'spouse_owner_id' => '',
            'acquired_on' => '', 'other_holdings' => '', 'transferred_shares' => false, 'topped_up_previous_year' => false, 'saved_space_carried' => false, 'cost_basis' => '', 'saved_space' => '', 'own_pay' => '',
            'related_pay' => '', 'company_pay' => '', 'subsidiary_pay' => '', 'dividend' => '',
        ];
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
