<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The payroll start page: the onboarding steps (PAYROLL_MODULE.md B.2) until
 * the first run, and later the payroll overview.
 */
class PayrollHome extends Component
{
    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:view'), 403);
    }

    public function render(): View
    {
        $company = $this->company();

        return view('payroll::livewire.home', [
            'company' => $company,
            'steps' => [
                ['title' => __('Löneinställningar'), 'text' => __('Utbetalningsdag, semesterregel, betalsätt och hur lönebeskeden når de anställda.'), 'link' => route('teams.show', ['company' => $company->id, 'flik' => 'moduler']), 'ready' => true],
                ['title' => __('Arbetsgivare hos Skatteverket'), 'text' => __('Företaget behöver vara registrerat som arbetsgivare innan den första lönen betalas ut.'), 'link' => route('teams.show', $company->id), 'ready' => $company->employer_registered],
                ['title' => __('Anställda'), 'text' => __('Lägg upp de anställda med skattetabell, bankkonto och anställningsform.'), 'link' => route('payroll.employees'), 'ready' => Employee::query()->exists()],
                ['title' => __('Regler och skattetabeller'), 'text' => __('Arbetsgivaravgifter, skattetabeller och andra satser för året måste vara verifierade innan en lönekörning kan göras.'), 'link' => route('payroll.rules'), 'ready' => RuleSet::for(CarbonImmutable::today())->missing() === []],
                ['title' => __('Första lönekörningen'), 'text' => __('Beräkna, kontrollera och attestera. Hämta lönefilen till banken och bokför lönen.'), 'link' => route('payroll.runs.index'), 'ready' => PayrollRun::query()->where('status', '<>', PayrollRun::DRAFT)->exists()],
                ['title' => __('Arbetsgivardeklaration'), 'text' => __('Skapa filen för utbetalningsmånaden och lämna in den hos Skatteverket senast deklarationsdagen.'), 'link' => route('payroll.agi'), 'ready' => AgiSubmission::query()->whereIn('status', AgiSubmission::REPORTED)->exists()],
            ],
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
