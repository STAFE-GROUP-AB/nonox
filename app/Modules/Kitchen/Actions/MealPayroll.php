<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Kitchen\Actions\Concerns\GuardsKitchen;
use App\Modules\Kitchen\Models\MealPayrollProposal;
use App\Modules\Kitchen\Support\MealBenefits;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Skicka kostförmåner till lönen" (ADR-073 §7, Lead decision O5): a
 * month's meal benefits become proposed lines per employee, which the
 * payroll administrator approves into that month's payroll run, where each
 * becomes an ordinary input through payroll's own action (and so its own
 * checks), or rejects. Nothing reaches pay without that approval. Sending
 * a month again replaces the lines still proposed; approved lines stay,
 * and a month with approved lines is not proposed again.
 */
class MealPayroll
{
    use GuardsKitchen;

    public function __construct(private readonly PayrollRuns $runs) {}

    /** @return int how many lines were proposed */
    public function propose(User $user, Company $company, string $period): int
    {
        $this->guard($user, $company, 'payroll');
        app(ModuleManager::class)->ensureEnabled(PayrollModule::KEY, $company);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw ValidationException::withMessages(['period' => __('Välj en månad.')]);
        }

        $benefits = MealBenefits::month($company, $period);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $period, $benefits): int {
            $approved = MealPayrollProposal::query()->where('period', $period)->where('status', MealPayrollProposal::APPROVED)->lockForUpdate()->pluck('employee_id')->unique()->all();
            MealPayrollProposal::query()->where('period', $period)->where('status', MealPayrollProposal::PROPOSED)->delete();
            $count = 0;
            $skipped = [];

            foreach ($benefits as $employeeId => $benefit) {
                if (in_array($employeeId, $approved, true)) {
                    $skipped[] = $employeeId;

                    continue;
                }

                foreach ($benefit['lines'] as $line) {
                    MealPayrollProposal::create([
                        'period' => $period,
                        'employee_id' => $employeeId,
                        'pay_code' => $line['pay_code'],
                        'quantity' => $line['quantity'] === null ? null : (string) $line['quantity'],
                        'amount_ore' => $line['amount_ore'],
                        'details' => [
                            'meals' => $benefit['meals'],
                            'paid_ore' => $benefit['paid_ore'],
                            'capped_ore' => $benefit['capped_ore'],
                            'excess_ore' => $benefit['excess_ore'],
                            'days' => $benefit['days'],
                        ],
                        'status' => MealPayrollProposal::PROPOSED,
                        'created_by' => $user->id,
                    ]);
                    $count++;
                }
            }

            Audit::record('kitchen.meal_benefits_proposed', null, ['period' => $period, 'lines' => $count, 'employees' => count($benefits) - count($skipped), 'skipped_employee_ids' => $skipped], $company, $user);

            return $count;
        }));
    }

    /**
     * Approves proposed lines into the month's payroll run, through
     * PayrollRuns::addInput (which checks the run is open and the user may
     * change it).
     *
     * @param  list<int>  $ids
     * @return int how many lines were approved
     */
    public function approve(User $user, Company $company, PayrollRun $run, array $ids): int
    {
        $this->guard($user, $company, 'payroll');

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $run, $ids): int {
            $run = PayrollRun::query()->findOrFail($run->id);
            $lines = $this->proposed($ids);
            $codes = PayCode::query()->where('is_active', true)->pluck('id', 'code');

            foreach ($lines as $line) {
                if ($line->period !== $run->period) {
                    throw ValidationException::withMessages(['run' => __('Raden gäller :period. Välj lönekörningen för den månaden.', ['period' => $line->period])]);
                }

                $code = $codes->get($line->pay_code) ?? throw ValidationException::withMessages(['run' => __('Lönearten :code finns inte i lönen.', ['code' => $line->pay_code])]);
                $input = $this->runs->addInput($user, $run, [
                    'employee_id' => $line->employee_id,
                    'pay_code_id' => $code,
                    'quantity' => $line->quantity === null ? null : rtrim(rtrim($line->quantity, '0'), '.'),
                    'amount' => $line->amount_ore === null ? null : sprintf('%d.%02d', intdiv($line->amount_ore, 100), $line->amount_ore % 100),
                    'note' => __('Personalmat :period (Kök)', ['period' => $line->period]),
                ]);

                $line->forceFill(['status' => MealPayrollProposal::APPROVED, 'payroll_run_id' => $run->id, 'payroll_input_id' => $input->id, 'decided_by' => $user->id, 'decided_at' => now()])->save();
            }

            Audit::record('kitchen.meal_benefits_approved', $run, ['proposal_ids' => $lines->pluck('id')->all()], $company, $user);

            return $lines->count();
        }));
    }

    /** @param  list<int>  $ids */
    public function reject(User $user, Company $company, array $ids): int
    {
        $this->guard($user, $company, 'payroll');

        return CurrentCompany::run($company, function () use ($user, $company, $ids): int {
            $lines = $this->proposed($ids);
            $lines->each(fn (MealPayrollProposal $line) => $line->forceFill(['status' => MealPayrollProposal::REJECTED, 'decided_by' => $user->id, 'decided_at' => now()])->save());

            Audit::record('kitchen.meal_benefits_rejected', null, ['proposal_ids' => $lines->pluck('id')->all()], $company, $user);

            return $lines->count();
        });
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, MealPayrollProposal>
     */
    private function proposed(array $ids): Collection
    {
        $lines = MealPayrollProposal::query()->whereKey($ids)->where('status', MealPayrollProposal::PROPOSED)->lockForUpdate()->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('Det finns inga föreslagna rader att besluta om.')]);
        }

        return $lines;
    }
}
