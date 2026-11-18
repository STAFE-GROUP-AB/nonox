<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveProjectDetails;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\Dimension;
use App\Domain\Accounting\VoucherStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\User;
use App\Models\VoucherRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The register of cost centres or of projects. A project also has its
 * register details (ADR-081 gap 6): customer, project manager, status,
 * dates, price form, contract sum, estimated cost and default cost centre.
 */
class Dimensions extends Component
{
    use ShowsToasts;

    #[Locked]
    public string $dimension;

    public string $newCode = '';

    public string $newName = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public ?int $detailsId = null;

    /** @var array<string, string> */
    public array $details = [];

    public function mount(string $dimension): void
    {
        $this->dimension = Dimension::from($dimension)->value;

        abort_unless($this->user()->can('viewAny', [$this->kind()->model(), $this->company()]), 403);
    }

    public function add(SaveDimensionObject $save): void
    {
        $this->resetErrorBag();

        $object = $save($this->user(), $this->company(), $this->kind(), ['code' => trim($this->newCode), 'name' => $this->newName]);

        $this->reset('newCode', 'newName');
        $this->toast(__(':label :name är tillagt.', ['label' => $this->kind()->label(), 'name' => $object->label()]));
    }

    public function startEditing(int $id): void
    {
        $object = $this->find($id);

        $this->editingId = $object->id;
        $this->editingName = $object->name;
        $this->resetErrorBag();
    }

    public function cancelEditing(): void
    {
        $this->reset('editingId', 'editingName');
    }

    public function saveName(SaveDimensionObject $save): void
    {
        $object = $save($this->user(), $this->company(), $this->kind(), ['name' => $this->editingName], $this->find((int) $this->editingId));

        $this->reset('editingId', 'editingName');
        $this->toast(__('Namnet är ändrat till :name.', ['name' => $object->name]));
    }

    public function toggleActive(int $id, SaveDimensionObject $save): void
    {
        $object = $this->find($id);
        $object = $save($this->user(), $this->company(), $this->kind(), ['name' => $object->name, 'is_active' => ! $object->is_active], $object);

        $this->toast($object->is_active
            ? __(':name är aktiverat igen.', ['name' => $object->label()])
            : __(':name är inaktiverat. Det går inte att välja på nya verifikationer, men finns kvar på gamla.', ['name' => $object->label()]));
    }

    public function startDetails(int $id): void
    {
        abort_unless($this->kind() === Dimension::Project, 404);

        /** @var Project $project */
        $project = $this->find($id);

        $this->detailsId = $project->id;
        $this->details = [
            'customer_id' => (string) $project->customer_id,
            'manager_user_id' => (string) $project->manager_user_id,
            'status' => $project->status->value,
            'starts_on' => (string) $project->starts_on?->toDateString(),
            'ends_on' => (string) $project->ends_on?->toDateString(),
            'price_form' => (string) $project->price_form?->value,
            'contract_sum' => $project->contract_sum_ore?->format(withCurrency: false) ?? '',
            'estimated_cost' => $project->estimated_cost_ore?->format(withCurrency: false) ?? '',
            'default_cost_center' => (string) $project->defaultCostCenter()->value('code'),
        ];
        $this->resetErrorBag();
    }

    public function cancelDetails(): void
    {
        $this->reset('detailsId', 'details');
    }

    public function saveDetails(SaveProjectDetails $save): void
    {
        abort_unless($this->kind() === Dimension::Project, 404);

        /** @var Project $project */
        $project = $this->find((int) $this->detailsId);
        $project = $save($this->user(), $this->company(), $project, $this->details);

        $this->reset('detailsId', 'details');
        $this->toast(__('Uppgifterna för projekt :name är sparade.', ['name' => $project->label()]));
    }

    public function render(): View
    {
        $model = $this->kind()->model();
        $isProject = $this->kind() === Dimension::Project;
        $objects = $isProject
            ? Project::query()->with(['customer:id,name', 'manager:id,name', 'defaultCostCenter:id,code,name'])->orderBy('code')->get()
            : CostCenter::query()->orderBy('code')->get();
        $canManage = $this->user()->can('create', [$model, $this->company()]);

        $fiscalYear = FiscalYear::query()->whereDate('start_date', '<=', now()->toDateString())->whereDate('end_date', '>=', now()->toDateString())->first()
            ?? FiscalYear::query()->orderByDesc('start_date')->first();
        $canSeeResult = $fiscalYear !== null && $this->user()->hasTeamPermission($this->company(), 'reports:view');

        return view('livewire.accounting.dimensions', [
            'kind' => $this->kind(),
            'objects' => $objects,
            'canManage' => $canManage,
            'fiscalYear' => $canSeeResult ? $fiscalYear : null,
            'results' => $canSeeResult ? $this->results($fiscalYear) : collect(),
            // A project manager is shown only while still a member of the company (ADR-081 gap 6).
            'memberIds' => $isProject ? $this->memberIds() : [],
            'members' => $isProject && $this->detailsId !== null ? $this->company()->allUsers()->sortBy('name')->values() : collect(),
            'customers' => $isProject && $this->detailsId !== null ? Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'number', 'name']) : collect(),
            'costCenters' => $isProject && $this->detailsId !== null ? CostCenter::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']) : collect(),
        ]);
    }

    /**
     * Income and costs per object for the fiscal year, from the posted rows
     * of income statement accounts; one query.
     *
     * @return Collection<int, array{income: int, costs: int}>
     */
    private function results(FiscalYear $fiscalYear): Collection
    {
        $column = 'voucher_rows.'.$this->kind()->column();

        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.fiscal_year_id', $fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereNotNull($column)
            ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->groupBy($column)
            ->select("{$column} as object_id")
            ->selectRaw('SUM(CASE WHEN accounts.type = ? THEN voucher_rows.credit_ore - voucher_rows.debit_ore ELSE 0 END) AS income, SUM(CASE WHEN accounts.type = ? THEN voucher_rows.debit_ore - voucher_rows.credit_ore ELSE 0 END) AS costs', [AccountType::Revenue->value, AccountType::Expense->value])
            ->get()
            ->mapWithKeys(fn (VoucherRow $row): array => [(int) $row->getAttribute('object_id') => ['income' => (int) $row->getAttribute('income'), 'costs' => (int) $row->getAttribute('costs')]]);
    }

    /**
     * The owner and members of the company; one query.
     *
     * @return list<int>
     */
    private function memberIds(): array
    {
        $company = $this->company();

        return array_values(array_unique([(int) $company->user_id, ...$company->users()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all()]));
    }

    private function kind(): Dimension
    {
        return Dimension::from($this->dimension);
    }

    private function find(int $id): CostCenter|Project
    {
        return $this->kind()->model()::query()->findOrFail($id);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
