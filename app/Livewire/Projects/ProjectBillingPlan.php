<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Actions\Projects\DeleteBillingMilestone;
use App\Actions\Projects\InvoiceBillingMilestone;
use App\Actions\Projects\SaveBillingMilestone;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Projects\MilestoneStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A project's billing plan on its page (ADR-081 gap 6 scope 5, E8):
 * milestones with a date and an amount or a share of the contract sum,
 * planned, ready or invoiced. "Fakturera" makes a draft invoice with the
 * project on its row. The work orders on the project keep their own a conto
 * instalments; they are shown alongside, read only.
 *
 * Not for a member limited to cost centres or projects (the page leaves the
 * component out for them, and the registry refuses it).
 *
 * A fixed number of queries, whatever the number of milestones.
 */
class ProjectBillingPlan extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $projectId;

    /** null: no form open; 0: a new milestone; otherwise the one being edited */
    #[Locked]
    public ?int $editing = null;

    /** The version of the milestone being edited, as it was opened. */
    #[Locked]
    public ?string $editingToken = null;

    /** @var array{description: string, due_on: string, kind: string, amount: string, share: string, status: string} */
    public array $form = ['description' => '', 'due_on' => '', 'kind' => 'AMOUNT', 'amount' => '', 'share' => '', 'status' => 'PLANNED'];

    public function mount(Project $project): void
    {
        Gate::authorize('viewBillingPlan', $project);

        $this->projectId = $project->id;
    }

    public function create(): void
    {
        $this->authorizeManage();
        $this->editing = 0;
        $this->editingToken = null;
        $this->form = ['description' => '', 'due_on' => '', 'kind' => 'AMOUNT', 'amount' => '', 'share' => '', 'status' => MilestoneStatus::Planned->value];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $milestone = $this->milestone($id);
        $this->editing = $milestone->id;
        $this->editingToken = $milestone->versionToken();
        $this->form = [
            'description' => $milestone->description,
            'due_on' => $milestone->due_on?->toDateString() ?? '',
            'kind' => $milestone->share_bp === null ? 'AMOUNT' : 'SHARE',
            'amount' => $milestone->amount_ore?->format(false) ?? '',
            'share' => $milestone->share_bp === null ? '' : rtrim(rtrim(number_format($milestone->share_bp / 100, 2, ',', ''), '0'), ','),
            'status' => $milestone->effectiveStatus() === MilestoneStatus::Ready ? MilestoneStatus::Ready->value : MilestoneStatus::Planned->value,
        ];
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->editingToken = null;
        $this->resetErrorBag();
    }

    public function save(SaveBillingMilestone $save): void
    {
        if ($this->editing === null) {
            return;
        }

        $milestone = $this->editing === 0 ? null : $this->milestone($this->editing);

        try {
            $saved = $save($this->user(), $this->company(), $this->project(), $this->form, $milestone, $this->editingToken);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->toast($milestone === null
            ? __('Delfaktureringen :description är tillagd i planen.', ['description' => $saved->description])
            : __('Delfaktureringen :description är sparad.', ['description' => $saved->description]));
        $this->cancel();
    }

    public function markReady(int $id, string $token, SaveBillingMilestone $save): void
    {
        $milestone = $this->milestone($id);

        try {
            $save($this->user(), $this->company(), $this->project(), [
                'description' => $milestone->description,
                'due_on' => $milestone->due_on?->toDateString() ?? '',
                'kind' => $milestone->share_bp === null ? 'AMOUNT' : 'SHARE',
                'amount' => $milestone->amount_ore?->format(false) ?? '',
                'share' => $milestone->share_bp === null ? '' : number_format($milestone->share_bp / 100, 2, ',', ''),
                'status' => MilestoneStatus::Ready->value,
            ], $milestone, $token);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Delfaktureringen :description är klar att fakturera.', ['description' => $milestone->description]));
    }

    public function delete(int $id, string $token, DeleteBillingMilestone $delete): void
    {
        $milestone = $this->milestone($id);

        try {
            $delete($this->user(), $this->company(), $milestone, $token);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Delfaktureringen :description är borttagen ur planen.', ['description' => $milestone->description]));
    }

    public function invoice(int $id, string $token, InvoiceBillingMilestone $invoice): void
    {
        $milestone = $this->milestone($id);

        try {
            $draft = $invoice($this->user(), $this->company(), $milestone, $token);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        Toast::flash(__('Ett fakturautkast är skapat för :description med 25 % moms. Kontrollera momssatsen och resten av fakturan, och skicka den när den stämmer.', ['description' => $milestone->description]));
        $this->redirectRoute('invoicing.invoices.edit', $draft->id);
    }

    public function render(): View
    {
        $project = Project::query()->with('customer:id,name')->findOrFail($this->projectId);
        Gate::authorize('viewBillingPlan', $project);

        $milestones = ProjectBillingMilestone::query()->where('project_id', $project->id)
            ->with('invoice:id,number,status')
            ->orderBy('position')->orderBy('id')->get();
        $contract = $project->contract_sum_ore?->ore;
        $planned = (int) $milestones->sum(fn (ProjectBillingMilestone $milestone): int => (int) $milestone->amountFor($contract));

        return view('livewire.projects.project-billing-plan', [
            'project' => $project,
            'milestones' => $milestones,
            'contract' => $contract,
            'planned' => $planned,
            // Issued invoices only; a milestone with a draft invoice counts as Utkast (cold review P3).
            'invoiced' => (int) $milestones->filter(fn (ProjectBillingMilestone $milestone): bool => $milestone->invoice !== null && $milestone->invoice->status !== InvoiceStatus::Draft)
                ->sum(fn (ProjectBillingMilestone $milestone): int => (int) $milestone->amountFor($contract)),
            'drafted' => (int) $milestones->filter(fn (ProjectBillingMilestone $milestone): bool => $milestone->invoice !== null && $milestone->invoice->status === InvoiceStatus::Draft)
                ->sum(fn (ProjectBillingMilestone $milestone): int => (int) $milestone->amountFor($contract)),
            'canManage' => Gate::allows('manageBillingPlan', $project),
            'canInvoice' => Gate::allows('invoiceMilestone', $project),
            'workOrders' => $this->workOrders($project),
        ]);
    }

    /**
     * The work orders on the project with their a conto instalments, read
     * only; empty while Arbetsorder is off. Two queries.
     *
     * @return Collection<int, WorkOrder>
     */
    private function workOrders(Project $project): Collection
    {
        if (! app(ModuleManager::class)->isEnabled(WorkOrdersModule::KEY, $this->company())) {
            return collect();
        }

        return WorkOrder::query()->where('project_id', $project->id)
            ->where('status', '<>', WorkOrderStatus::Cancelled->value)
            ->with(['instalments' => fn ($query) => $query->orderBy('position')->with('invoiceLink')])
            ->orderBy('number')
            ->get();
    }

    private function authorizeManage(): void
    {
        if (! Gate::allows('manageBillingPlan', $this->project())) {
            throw new AuthorizationException;
        }
    }

    private function milestone(int $id): ProjectBillingMilestone
    {
        return ProjectBillingMilestone::query()->where('project_id', $this->projectId)->findOrFail($id);
    }

    private function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
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
