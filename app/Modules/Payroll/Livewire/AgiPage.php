<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Agi\AgiDeadlines;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Arbetsgivardeklarationer per payment month: when each is due, the file to
 * upload at Skatteverket, and what happened to it.
 */
class AgiPage extends Component
{
    use ShowsToasts;

    private const int MONTHS_SHOWN = 15;

    /** @var array<int, string> submission id => receipt number */
    public array $receipts = [];

    public function mount(): void
    {
        abort_unless($this->can('payroll:agi-submit') || $this->can('payroll:archive-view'), 403);
    }

    public function generate(AgiSubmissions $submissions, string $period): void
    {
        $submission = $submissions->generate($this->user(), $this->company(), $period);

        $this->toast($submission->sequence > 1
            ? __('Rättelsen för :period är skapad. Hämta filen och lämna in den hos Skatteverket.', ['period' => $period])
            : __('Arbetsgivardeklarationen för :period är skapad. Hämta filen och lämna in den hos Skatteverket.', ['period' => $period]));
    }

    public function submitted(AgiSubmissions $submissions, int $id): void
    {
        $submission = $submissions->submitted($this->user(), AgiSubmission::query()->findOrFail($id), $this->receipts[$id] ?? null);

        $this->toast(__('Arbetsgivardeklarationen för :period är markerad som inlämnad.', ['period' => $submission->period]));
    }

    public function decided(AgiSubmissions $submissions, int $id, bool $accepted): void
    {
        $submission = $submissions->decided($this->user(), AgiSubmission::query()->findOrFail($id), $accepted);

        $this->toast($accepted
            ? __('Arbetsgivardeklarationen för :period är godkänd av Skatteverket.', ['period' => $submission->period])
            : __('Arbetsgivardeklarationen för :period är markerad som avvisad. Rätta och skapa en ny fil.', ['period' => $submission->period]), $accepted ? 'success' : 'error');
    }

    public function render(): View
    {
        $today = CarbonImmutable::today();
        $from = $today->startOfMonth()->subMonths(self::MONTHS_SHOWN - 1);
        $group = (string) (app(ModuleManager::class)->settings(PayrollModule::KEY, $this->company())['agi_deadline_group'] ?? AgiDeadlines::SMALL);
        // Only a VAT-registered company can have a VAT base above 40 million kronor (SFL 26 kap. 32 §).
        $group = $this->company()->isVatRegistered() ? $group : AgiDeadlines::SMALL;
        $runs = PayrollRun::query()->whereDate('payment_date', '>=', $from->toDateString())->get()->groupBy(fn (PayrollRun $run): string => $run->payment_date->format('Y-m'));
        $submissions = AgiSubmission::query()->where('period', '>=', $from->format('Y-m'))->orderByDesc('sequence')->get()->groupBy('period');
        $periods = [];

        foreach ($runs->keys()->merge($submissions->keys())->unique()->sortDesc() as $period) {
            $periodRuns = $runs->get($period, collect());
            $periodSubmissions = $submissions->get($period, collect());
            $latest = $periodSubmissions->first();
            $reported = $periodSubmissions->contains(fn (AgiSubmission $submission): bool => in_array($submission->status, AgiSubmission::REPORTED, true) || $submission->status === AgiSubmission::CORRECTED);
            $deadline = AgiDeadlines::for((string) $period, $group);

            // A large employer pays two weeks before declaring (SFL 62 kap. 3 § andra stycket).
            $payment = AgiDeadlines::paymentDate((string) $period, $group);

            $periods[] = [
                'period' => (string) $period,
                'deadline' => $deadline,
                'payment' => $payment->equalTo($deadline) ? null : $payment,
                'overdue' => $deadline->lt($today) && ! $reported,
                'runs' => $periodRuns->count(),
                'open_runs' => $periodRuns->reject(fn (PayrollRun $run): bool => in_array($run->status, [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED], true))->count(),
                'reported' => $reported,
                'latest' => $latest,
                'submissions' => $periodSubmissions,
            ];
        }

        $next = collect($periods)->first(fn (array $row): bool => ! $row['reported'] && $row['runs'] > 0);

        return view('payroll::livewire.agi', [
            'periods' => $periods,
            'next' => $next,
            'canSubmit' => $this->can('payroll:agi-submit'),
        ]);
    }

    private function can(string $permission): bool
    {
        return $this->user()->hasTeamPermission($this->company(), $permission);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        return $this->user()->currentCompany() ?? abort(404);
    }
}
