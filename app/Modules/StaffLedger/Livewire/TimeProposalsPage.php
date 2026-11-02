<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Modules\StaffLedger\Actions\TimeProposals;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Models\TimeProposal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Personalliggare → Tidrapporter (payroll on): propose hours per day from
 * the ledger, then approve or reject each proposal.
 */
class TimeProposalsPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public string $from = '';

    public string $to = '';

    public function mount(ModuleManager $modules): void
    {
        abort_unless($this->allowed('staff-ledger:manage') && $modules->isEnabled(PayrollModule::KEY, $this->company()), 404);

        $last = CarbonImmutable::today()->subMonthNoOverflow();
        $this->from = $last->startOfMonth()->toDateString();
        $this->to = $last->endOfMonth()->toDateString();
    }

    public function propose(TimeProposals $proposals): void
    {
        $from = CarbonImmutable::createFromFormat('Y-m-d', $this->from) ?: null;
        $to = CarbonImmutable::createFromFormat('Y-m-d', $this->to) ?: null;

        if ($from === null || $to === null) {
            $this->toast(__('Välj en period.'), 'error');

            return;
        }

        try {
            $created = $proposals->propose($this->user(), $this->company(), $from, $to);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($created === []
            ? __('Inga avslutade arbetspass för personal som är kopplad till lönen under perioden.')
            : __(':count förslag är skapade. Granska och godkänn dem nedan.', ['count' => count($created)]));
    }

    public function approve(int $id, TimeProposals $proposals): void
    {
        try {
            $days = $proposals->approve($this->user(), $this->company(), TimeProposal::query()->findOrFail($id));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Godkänt. :count dagar är tidrapporterade i lönen.', ['count' => $days]));
    }

    public function reject(int $id, TimeProposals $proposals): void
    {
        $proposals->reject($this->user(), $this->company(), TimeProposal::query()->findOrFail($id));
        $this->toast(__('Förslaget är avslaget. Inget skickades till lönen.'));
    }

    public function render(): View
    {
        return view('staff-ledger::livewire.proposals', [
            'planning' => TimeProposals::replacedByPlanning($this->company()),
            'proposals' => TimeProposal::query()->with('employee:id,first_name,last_name,employee_number')->latest('id')->limit(50)->get(),
        ]);
    }
}
