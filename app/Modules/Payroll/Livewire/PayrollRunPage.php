<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Policies\PayrollRunPolicy;
use App\Modules\Payroll\Support\Run\TimeReportPay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One payroll run: hours and other inputs, absence, the calculation with
 * what blocks it, each payslip with its lines and explanations, and
 * approval.
 */
class PayrollRunPage extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $runId;

    /** @var array{employee_id: string, pay_code_id: string, quantity: string, amount: string, note: string} */
    public array $input = ['employee_id' => '', 'pay_code_id' => '', 'quantity' => '', 'amount' => '', 'note' => ''];

    /** @var array{employee_id: string, type: string, date_from: string, date_to: string, hours: string} */
    public array $absence = ['employee_id' => '', 'type' => 'SICK', 'date_from' => '', 'date_to' => '', 'hours' => '8'];

    /** @var list<string> */
    public array $blockers = [];

    /** The payment date whose rules are not checked, to link to them. */
    public ?string $rulesDate = null;

    public function mount(PayrollRun $run): void
    {
        abort_unless(Gate::allows('view', $run), 403);

        $this->runId = $run->id;
        // Skatteverket draws tax and contributions around the 12th of the month after payment.
        $this->taxDate = $run->payment_date->addMonthNoOverflow()->day(12)->toDateString();
    }

    public function addInput(PayrollRuns $runs): void
    {
        $runs->addInput($this->user(), $this->run(), $this->input);

        $this->input = ['employee_id' => $this->input['employee_id'], 'pay_code_id' => '', 'quantity' => '', 'amount' => '', 'note' => ''];
        $this->toast(__('Raden är tillagd. Beräkna lönekörningen igen.'));
    }

    public function removeInput(int $inputId, PayrollRuns $runs): void
    {
        $runs->removeInput($this->user(), $this->run(), PayrollInput::query()->where('payroll_run_id', $this->runId)->findOrFail($inputId));

        $this->toast(__('Raden är borttagen. Beräkna lönekörningen igen.'));
    }

    public function recordAbsence(PayrollRuns $runs): void
    {
        $runs->recordAbsence($this->user(), $this->run(), $this->absence);

        $this->absence = ['employee_id' => '', 'type' => 'SICK', 'date_from' => '', 'date_to' => '', 'hours' => '8'];
        $this->toast(__('Frånvaron är registrerad. Beräkna lönekörningen igen.'));
    }

    public function calculate(PayrollRuns $runs): void
    {
        $this->blockers = [];
        $this->rulesDate = null;

        try {
            $runs->calculate($this->user(), $this->run());
        } catch (ValidationException $exception) {
            $this->blockers = array_values(array_map('strval', $exception->errors()['run'] ?? [__('Lönekörningen kunde inte beräknas.')]));
            $this->rulesDate = isset($exception->errors()['rules'][0]) ? (string) $exception->errors()['rules'][0] : null;
            $this->toast(__('Lönekörningen kunde inte beräknas. Se vad som saknas.'), 'error');

            return;
        }

        $this->toast(__('Lönekörningen är beräknad. Kontrollera lönebeskeden och skicka den för attest.'));
    }

    public function reopen(PayrollRuns $runs): void
    {
        $runs->reopen($this->user(), $this->run());

        $this->toast(__('Lönekörningen är öppnad igen.'));
    }

    public function approve(PayrollRuns $runs): void
    {
        $runs->approve($this->user(), $this->run());

        $this->toast(__(':title är attesterad och kan inte längre ändras.', ['title' => $this->run()->title()]));
    }

    public function markPaid(PayrollRuns $runs): void
    {
        $runs->markPaid($this->user(), $this->run());

        $this->toast(__(':title är markerad som utbetald.', ['title' => $this->run()->title()]));
    }

    public function post(PayrollRuns $runs): void
    {
        $run = $runs->post($this->user(), $this->run());

        $this->toast(__(':title är bokförd som :voucher.', ['title' => $run->title(), 'voucher' => $run->voucher?->reference()]));
    }

    public string $taxDate = '';

    public function postTax(PayrollRuns $runs): void
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->taxDate) === 1 ? CarbonImmutable::parse($this->taxDate) : null;

        if ($date === null) {
            $this->addError('run', __('Välj den dag skatten dras från skattekontot.'));

            return;
        }

        $run = $runs->postTax($this->user(), $this->run(), $date);
        $this->toast(__('Skatt och avgifter är bokförda mot skattekontot som :voucher.', ['voucher' => $run->taxVoucher?->reference()]));
    }

    public function addAccounts(AddPayrollAccounts $add): void
    {
        $company = $this->user()->currentCompany() ?? abort(404);
        $added = $add($this->user(), $company);

        $this->toast($added === [] ? __('Alla konton finns redan.') : __('Kontona :accounts är tillagda i kontoplanen.', ['accounts' => implode(', ', $added)]));
    }

    public function render(): View
    {
        $run = $this->run();
        $user = $this->user();
        $company = $user->currentCompany();
        $canSeeSensitive = $company !== null && $user->hasTeamPermission($company, 'payroll:view-sensitive');
        $pendingHours = $run->isOpen() ? app(TimeReportPay::class)->pendingHours($run->periodStart(), $run->periodEnd()) : null;

        return view('payroll::livewire.run-page', [
            'run' => $run,
            'employees' => Employee::query()->orderBy('employee_number')->get(['id', 'employee_number', 'first_name', 'last_name']),
            'payCodes' => PayCode::query()->where('is_active', true)->whereNotIn('category', ['SICK', 'ABSENCE', 'VACATION', 'PENSION', 'ALLOWANCE_TAXABLE'])->orderBy('code')->get(['id', 'code', 'name_sv', 'unit']),
            'inputs' => PayrollInput::query()->with(['employee:id,first_name,last_name', 'payCode:id,code,name_sv'])->where('payroll_run_id', $run->id)->orderBy('id')->get(),
            'absences' => Absence::query()->with('employee:id,first_name,last_name')->where('status', 'APPROVED')
                ->whereDate('date_from', '<=', $run->periodEnd()->toDateString())->whereDate('date_to', '>=', $run->periodStart()->toDateString())->orderBy('date_from')->get(),
            'payslips' => Payslip::query()->with(['employee:id,employee_number,first_name,last_name', 'lines'])->where('payroll_run_id', $run->id)->get()->sortBy('employee.employee_number'),
            'canEdit' => Gate::allows('update', $run),
            'canApprove' => Gate::allows('approve', $run),
            'blockedBySegregation' => $run->status === PayrollRun::CALCULATED && (new PayrollRunPolicy)->blockedBySegregation($user, $run),
            'canSeeSensitive' => $canSeeSensitive,
            'canPay' => Gate::allows('pay', $run),
            'canPost' => Gate::allows('post', $run),
            'canPostTax' => $company !== null && $user->hasTeamPermission($company, 'payroll:post'),
            'units' => PayCode::query()->pluck('unit', 'id'),
            'pendingHours' => $pendingHours !== null && $pendingHours->isPositive() ? self::hours((string) $pendingHours) : null,
            'missingAccounts' => $company === null ? [] : AddPayrollAccounts::missing($company),
        ]);
    }

    /** Hours as Swedish text: 12,5 and 8, not 12.50 and 8.00. */
    public static function hours(string $hours): string
    {
        return str_contains($hours, '.') ? rtrim(rtrim(str_replace('.', ',', $hours), '0'), ',') : $hours;
    }

    private function run(): PayrollRun
    {
        return PayrollRun::query()->findOrFail($this->runId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
