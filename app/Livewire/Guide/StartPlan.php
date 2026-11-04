<?php

declare(strict_types=1);

namespace App\Livewire\Guide;

use App\Actions\Guide\CompleteGuideStep;
use App\Actions\Guide\UncompleteGuideStep;
use App\Http\Controllers\GuideTipsController;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CompanyGuideStep;
use App\Models\User;
use App\Support\Guide\Guide;
use App\Support\Guide\StartPlanStep;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Min startplan (/foretagsguiden/min-startplan, ADR-051): the steps of
 * Företagsguiden's start plan that apply to the current company, with the
 * company's progress. Members with company:update tick them; everyone else
 * reads. The steps are files, the progress one query with its people.
 */
class StartPlan extends Component
{
    use ShowsToasts;

    public function toggle(string $stepKey, bool $done, CompleteGuideStep $complete, UncompleteGuideStep $uncomplete): void
    {
        if ($done) {
            $complete($this->user(), $this->company(), $stepKey);
            $this->toast(__('Steget är markerat som klart.'));

            return;
        }

        $uncomplete($this->user(), $this->company(), $stepKey);
        $this->toast(__('Steget är öppnat igen.'));
    }

    public function render(): View
    {
        $company = $this->company();
        $steps = Guide::forCompany($company);
        $progress = CompanyGuideStep::query()
            ->with('completer:id,name')
            ->whereIn('step_key', array_map(fn (StartPlanStep $step): string => $step->key, $steps))
            ->get()
            ->keyBy('step_key');

        return view('livewire.guide.start-plan', [
            'company' => $company,
            'steps' => $steps,
            'progress' => $progress,
            'done' => $progress->count(),
            'canTick' => $this->user()->hasTeamPermission($company, 'company:update'),
            'tipsHidden' => $this->user()->hidesPanel(GuideTipsController::PANEL, $company),
        ]);
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
