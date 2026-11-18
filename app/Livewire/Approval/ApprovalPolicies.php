<?php

declare(strict_types=1);

namespace App\Livewire\Approval;

use App\Actions\Approval\DeleteApprovalPolicy;
use App\Actions\Approval\ManageApprovalDelegations;
use App\Actions\Approval\SaveApprovalPolicy;
use App\Actions\Approval\SetDimensionOwner;
use App\Domain\Approval\ApprovalDimension;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\ApproverKind;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Attestflöden (ADR-081 gap 8): the company's own approval policies for
 * supplier invoices and payment files, the owners of cost centres and
 * projects, and substitutes. Every member reads the page and sets their own
 * substitute; approvals:manage edits the rest. The page runs a fixed number
 * of queries however many policies, steps and substitutes there are.
 */
class ApprovalPolicies extends Component
{
    use ShowsToasts;

    /** The policy being edited: null for none, 0 for a new one. */
    public ?int $editingId = null;

    /** @var array{subject_type: string, name: string, priority: string, is_active: bool, steps: array<int, array<string, mixed>>} */
    public array $form = ['subject_type' => 'SUPPLIER_INVOICE', 'name' => '', 'priority' => '10', 'is_active' => true, 'steps' => []];

    /** @var array{user_id: string, substitute_user_id: string, starts_on: string, ends_on: string} */
    public array $delegation = ['user_id' => '', 'substitute_user_id' => '', 'starts_on' => '', 'ends_on' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [ApprovalPolicy::class, $this->company()]), 403);

        $this->delegation['user_id'] = (string) $this->user()->id;
        $this->delegation['starts_on'] = CarbonImmutable::today()->toDateString();
    }

    public function create(string $type): void
    {
        $this->authorizeManage();

        $subject = ApprovalSubjectType::from($type);
        abort_unless($subject->isWired() && $this->offers($subject), 404);

        $this->resetErrorBag();
        $this->editingId = 0;
        $this->form = [
            'subject_type' => $subject->value,
            'name' => '',
            'priority' => '10',
            'is_active' => true,
            'steps' => [self::blankStep($subject)],
        ];
    }

    public function edit(int $policyId): void
    {
        $this->authorizeManage();

        $policy = ApprovalPolicy::query()->with('steps')->findOrFail($policyId);

        $this->resetErrorBag();
        $this->editingId = $policy->id;
        $this->form = [
            'subject_type' => $policy->subject_type->value,
            'name' => $policy->name,
            'priority' => (string) $policy->priority,
            'is_active' => $policy->is_active,
            'steps' => $policy->steps->map(fn (ApprovalPolicyStep $step): array => self::formStep($step))->values()->all(),
        ];
    }

    public function cancel(): void
    {
        $this->reset('editingId');
        $this->resetErrorBag();
    }

    public function addStep(): void
    {
        if (count($this->form['steps']) < SaveApprovalPolicy::MAX_STEPS) {
            $this->form['steps'][] = self::blankStep(ApprovalSubjectType::from($this->form['subject_type']));
        }
    }

    public function removeStep(int $index): void
    {
        if (count($this->form['steps']) > 1) {
            unset($this->form['steps'][$index]);
            $this->form['steps'] = array_values($this->form['steps']);
        }
    }

    public function save(SaveApprovalPolicy $save): void
    {
        $this->resetErrorBag();

        $input = $this->form;
        $input['steps'] = array_map(function (array $step): array {
            $step['approver_user_ids'] = array_map('intval', array_keys(array_filter((array) ($step['approver_user_ids'] ?? []))));

            return $step;
        }, $input['steps']);

        $policy = $save($this->user(), $this->company(), $input, $this->editingId ?: null);

        $this->reset('editingId');
        $this->toast(__('Attestflödet :name är sparat (version :version).', ['name' => $policy->name, 'version' => $policy->version]));
    }

    public function delete(int $policyId, DeleteApprovalPolicy $delete): void
    {
        $name = ApprovalPolicy::query()->findOrFail($policyId)->name;
        $delete($this->user(), $this->company(), $policyId);

        if ($this->editingId === $policyId) {
            $this->reset('editingId');
        }

        $this->toast(__('Attestflödet :name är borttaget. Attester som redan pågår följer det till slut.', ['name' => $name]));
    }

    public function setOwner(string $dimension, int $id, string $ownerId, SetDimensionOwner $set): void
    {
        $set($this->user(), $this->company(), ApprovalDimension::from($dimension), $id, $ownerId === '' ? null : (int) $ownerId);

        $this->toast($ownerId === '' ? __('Ägaren är borttagen.') : __('Ägaren är sparad.'));
    }

    public function addDelegation(ManageApprovalDelegations $delegations): void
    {
        $this->resetErrorBag();

        $delegation = $delegations->add($this->user(), $this->company(), $this->delegation);

        $this->delegation['substitute_user_id'] = '';
        $this->delegation['ends_on'] = '';
        $this->toast(__('Ersättaren gäller från :from till och med :to.', ['from' => $delegation->starts_on->toDateString(), 'to' => $delegation->ends_on->toDateString()]));
    }

    public function revokeDelegation(int $delegationId, ManageApprovalDelegations $delegations): void
    {
        $delegations->revoke($this->user(), $this->company(), $delegationId);

        $this->toast(__('Ersättaren är återkallad.'));
    }

    public function render(): View
    {
        $company = $this->company();
        $canManage = $this->user()->can('manage', [ApprovalPolicy::class, $company]);
        $members = $company->allUsers()->sortBy('name')->values();
        $costCenters = CostCenter::query()->orderBy('code')->get(['id', 'code', 'name', 'is_active', 'owner_user_id']);
        $projects = Project::query()->orderBy('code')->get(['id', 'code', 'name', 'is_active', 'owner_user_id']);
        $suppliers = Supplier::query()->orderBy('name')->get(['id', 'name']);

        return view('livewire.approval.approval-policies', [
            'company' => $company,
            'canManage' => $canManage,
            'types' => array_values(array_filter(ApprovalSubjectType::wired(), fn (ApprovalSubjectType $type): bool => $this->offers($type))),
            'policies' => ApprovalPolicy::query()->with('steps')->orderBy('priority')->orderBy('id')->get()->groupBy(fn (ApprovalPolicy $policy): string => $policy->subject_type->value),
            'members' => $members,
            'memberNames' => $members->pluck('name', 'id')->all(),
            'delegations' => ApprovalDelegation::query()
                ->whereNull('revoked_at')
                ->where('ends_on', '>=', CarbonImmutable::today()->toDateString())
                ->when(! $canManage, fn ($query) => $query->where(fn ($own) => $own->where('user_id', $this->user()->id)->orWhere('substitute_user_id', $this->user()->id)))
                ->orderBy('starts_on')
                ->orderBy('id')
                ->get(),
            'costCenters' => $costCenters,
            'projects' => $projects,
            'suppliers' => $suppliers,
            'costCenterNames' => $costCenters->mapWithKeys(fn (CostCenter $object): array => [$object->id => $object->label()])->all(),
            'projectNames' => $projects->mapWithKeys(fn (Project $object): array => [$object->id => $object->label()])->all(),
            'supplierNames' => $suppliers->pluck('name', 'id')->all(),
            'userId' => $this->user()->id,
        ]);
    }

    /** @return array<string, mixed> */
    private static function blankStep(ApprovalSubjectType $type): array
    {
        return [
            'approver_kind' => ApproverKind::Permission->value,
            'approver_permission' => $type->basePermission(),
            'approver_user_ids' => [],
            'approver_dimension' => ApprovalDimension::CostCenter->value,
            'required_count' => '1',
            'exclude_preparer' => true,
            'min_amount' => '',
            'max_amount' => '',
            'cost_center_id' => '',
            'project_id' => '',
            'supplier_id' => '',
            'account_from' => '',
            'account_to' => '',
        ];
    }

    /** @return array<string, mixed> */
    private static function formStep(ApprovalPolicyStep $step): array
    {
        $conditions = $step->stepConditions();
        $money = fn (?int $ore): string => $ore === null ? '' : Money::ofOre($ore)->format(withCurrency: false);

        return [
            'approver_kind' => $step->approver_kind->value,
            'approver_permission' => (string) $step->approver_permission,
            'approver_user_ids' => array_fill_keys(array_map('strval', $step->approver_user_ids ?? []), true),
            'approver_dimension' => $step->approver_dimension->value ?? ApprovalDimension::CostCenter->value,
            'required_count' => (string) $step->required_count,
            'exclude_preparer' => $step->exclude_preparer,
            'min_amount' => $money($conditions->minOre),
            'max_amount' => $money($conditions->maxOre),
            'cost_center_id' => (string) ($conditions->costCenterIds[0] ?? ''),
            'project_id' => (string) ($conditions->projectIds[0] ?? ''),
            'supplier_id' => (string) ($conditions->supplierIds[0] ?? ''),
            'account_from' => (string) ($conditions->accountFrom ?? ''),
            'account_to' => (string) ($conditions->accountTo ?? ''),
        ];
    }

    private function authorizeManage(): void
    {
        abort_unless($this->user()->can('manage', [ApprovalPolicy::class, $this->company()]), 403);
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

    /** Purchase orders belong to Lager (ADR-081 E10): their flows are offered while it is on for the company. */
    private function offers(ApprovalSubjectType $type): bool
    {
        return $type !== ApprovalSubjectType::PurchaseOrder || app(ModuleManager::class)->isEnabled(InventoryModule::KEY, $this->company());
    }
}
