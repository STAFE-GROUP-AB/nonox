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
use App\Modules\Payroll\Models\PayrollInput;
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
 * checks), or rejects. Nothing reaches pay without that approval, and an
 * employee's month is approved as a whole. Sending a month again replaces
 * the lines still proposed; meals added or corrected after approval are
 * proposed against what was approved and marked "ändrad efter
 * godkännande".
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
            $approved = MealPayrollProposal::query()->where('period', $period)->where('status', MealPayrollProposal::APPROVED)->lockForUpdate()->get()->groupBy('employee_id');
            MealPayrollProposal::query()->where('period', $period)->where('status', MealPayrollProposal::PROPOSED)->delete();
            $count = 0;
            $changed = [];

            foreach (array_unique([...array_keys($benefits), ...$approved->keys()->map(fn (mixed $id): int => (int) $id)->all()]) as $employeeId) {
                $benefit = $benefits[$employeeId] ?? ['lines' => [], 'meals' => 0, 'paid_ore' => 0, 'capped_ore' => 0, 'excess_ore' => 0, 'days' => []];
                $target = self::totals($benefit['lines']);
                $done = $approved->get($employeeId, collect());
                $lines = $benefit['lines'];
                $details = ['meals' => $benefit['meals'], 'paid_ore' => $benefit['paid_ore'], 'capped_ore' => $benefit['capped_ore'], 'excess_ore' => $benefit['excess_ore'], 'days' => $benefit['days']];

                if ($done->isNotEmpty()) {
                    $sent = self::totals(array_values($done->map(fn (MealPayrollProposal $line): array => ['pay_code' => $line->pay_code, 'quantity' => $line->quantity === null ? null : (int) $line->quantity, 'amount_ore' => $line->amount_ore])->all()));

                    if ($sent == $target) {
                        continue;
                    }

                    $changed[] = $employeeId;
                    [$lines, $replaces] = self::afterApproval($done, $target, $sent);
                    $details['changed_after_approval'] = true;
                    $details['replaces'] = $replaces;
                    $details['not_sent'] = array_values(array_filter($lines, fn (array $line): bool => ($line['quantity'] ?? 0) < 0 || ($line['amount_ore'] ?? 0) < 0));
                    $lines = array_values(array_filter($lines, fn (array $line): bool => ($line['quantity'] ?? 0) >= 0 && ($line['amount_ore'] ?? 0) >= 0 && ($line['quantity'] ?? 0) + ($line['amount_ore'] ?? 0) > 0));

                    if ($lines === [] && $details['not_sent'] !== []) {
                        // Only reductions, in a run that is closed: one empty line carries the warning for the administrator.
                        $lines = [['pay_code' => $details['not_sent'][0]['pay_code'], 'quantity' => null, 'amount_ore' => 0]];
                    }
                }

                foreach ($lines as $line) {
                    MealPayrollProposal::create([
                        'period' => $period,
                        'employee_id' => $employeeId,
                        'pay_code' => $line['pay_code'],
                        'quantity' => $line['quantity'] === null ? null : (string) $line['quantity'],
                        'amount_ore' => $line['amount_ore'],
                        'details' => $details,
                        'status' => MealPayrollProposal::PROPOSED,
                        'created_by' => $user->id,
                    ]);
                    $count++;
                }
            }

            Audit::record('kitchen.meal_benefits_proposed', null, ['period' => $period, 'lines' => $count, 'changed_after_approval_employee_ids' => $changed], $company, $user);

            return $count;
        }));
    }

    /**
     * An employee whose meals changed after the lines were approved.
     * Proposing never touches a run (Lead, re-check P2-b). While the run
     * that holds the approved lines is still open (draft or calculated) the
     * whole month is proposed again, and approving it takes the old inputs
     * out of that run first, so a correction's own payment is taken back
     * too. Once that run is approved or paid, only the difference is
     * proposed: an addition goes into the next open run (re-check P2-a), a
     * reduction is returned with a negative figure and shown, for the
     * administrator to correct by hand.
     *
     * @param  Collection<int, MealPayrollProposal>  $done
     * @param  array<string, array{quantity: int, amount_ore: int}>  $target
     * @param  array<string, array{quantity: int, amount_ore: int}>  $sent
     * @return array{0: list<array{pay_code: string, quantity: ?int, amount_ore: ?int}>, 1: list<int>} the lines, and the approved lines they replace
     */
    private static function afterApproval(Collection $done, array $target, array $sent): array
    {
        $runs = PayrollRun::query()->whereKey($done->pluck('payroll_run_id')->filter()->unique()->all())->get();

        if ($runs->isNotEmpty() && $runs->every(fn (PayrollRun $run): bool => self::isOpen($run))) {
            return [self::lines($target), array_values(array_map(intval(...), $done->pluck('id')->all()))];
        }

        $difference = [];

        foreach (array_unique([...array_keys($target), ...array_keys($sent)]) as $code) {
            $difference[$code] = [
                'quantity' => ($target[$code]['quantity'] ?? 0) - ($sent[$code]['quantity'] ?? 0),
                'amount_ore' => ($target[$code]['amount_ore'] ?? 0) - ($sent[$code]['amount_ore'] ?? 0),
            ];
        }

        return [self::lines($difference), []];
    }

    private static function isOpen(PayrollRun $run): bool
    {
        return in_array($run->status, [PayrollRun::DRAFT, PayrollRun::CALCULATED], true);
    }

    /**
     * @param  list<array{pay_code: string, quantity: ?int, amount_ore: ?int}>  $lines
     * @return array<string, array{quantity: int, amount_ore: int}>
     */
    private static function totals(array $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $totals[$line['pay_code']]['quantity'] = ($totals[$line['pay_code']]['quantity'] ?? 0) + (int) ($line['quantity'] ?? 0);
            $totals[$line['pay_code']]['amount_ore'] = ($totals[$line['pay_code']]['amount_ore'] ?? 0) + (int) ($line['amount_ore'] ?? 0);
        }

        ksort($totals);

        return $totals;
    }

    /**
     * @param  array<string, array{quantity: int, amount_ore: int}>  $totals
     * @return list<array{pay_code: string, quantity: ?int, amount_ore: ?int}>
     */
    private static function lines(array $totals): array
    {
        $lines = [];

        foreach ($totals as $code => $total) {
            if ($total['quantity'] === 0 && $total['amount_ore'] === 0) {
                continue;
            }

            $lines[] = $code === MealBenefits::OWN_PAYMENT
                ? ['pay_code' => $code, 'quantity' => null, 'amount_ore' => $total['amount_ore']]
                : ['pay_code' => $code, 'quantity' => $total['quantity'], 'amount_ore' => null];
        }

        return $lines;
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
            // An employee's month is approved as a unit, never a subset of its lines (review P3.8).
            $picked = $this->proposed($ids);
            $lines = MealPayrollProposal::query()->whereIn('employee_id', $picked->pluck('employee_id')->unique()->all())->whereIn('period', $picked->pluck('period')->unique()->all())->where('status', MealPayrollProposal::PROPOSED)->lockForUpdate()->orderBy('id')->get();
            $codes = PayCode::query()->where('is_active', true)->pluck('id', 'code');

            if (! self::isOpen($run)) {
                throw ValidationException::withMessages(['run' => __('Lönekörningen är redan attesterad. Välj en öppen lönekörning.')]);
            }

            // Approving replaces what was approved before for a changed month: the old inputs leave their (still open) run first.
            $replaced = MealPayrollProposal::query()->whereKey($lines->flatMap(fn (MealPayrollProposal $line): array => (array) ($line->details['replaces'] ?? []))->unique()->values()->all())
                ->where('status', MealPayrollProposal::APPROVED)->lockForUpdate()->get();

            foreach ($replaced as $old) {
                $oldRun = PayrollRun::query()->lockForUpdate()->find($old->payroll_run_id);

                if (! $oldRun instanceof PayrollRun || ! self::isOpen($oldRun)) {
                    throw ValidationException::withMessages(['run' => __('Lönekörningen för :period har attesterats sedan förslaget gjordes. Skicka kostförmånerna till lönen på nytt.', ['period' => $old->period])]);
                }

                $input = $old->payroll_input_id === null ? null : PayrollInput::query()->find($old->payroll_input_id);

                if ($input instanceof PayrollInput) {
                    $this->runs->removeInput($user, $oldRun, $input);
                }

                $old->forceFill(['status' => MealPayrollProposal::REPLACED, 'payroll_input_id' => null])->save();
            }

            foreach ($lines as $line) {
                $late = $line->period !== $run->period;

                // A line added after its own month's run was approved or paid goes into a later open regular run (Lead, re-check P2-a).
                if ($late && ! ($run->type === 'REGULAR' && $run->period > $line->period && self::periodClosed($line->period))) {
                    throw ValidationException::withMessages(['run' => __('Raden gäller :period. Välj lönekörningen för den månaden, eller en senare om den månadens lön redan är attesterad.', ['period' => $line->period])]);
                }

                if ($line->quantity === null && (int) $line->amount_ore === 0) {
                    $line->forceFill(['status' => MealPayrollProposal::APPROVED, 'payroll_run_id' => $run->id, 'decided_by' => $user->id, 'decided_at' => now()])->save();

                    continue;
                }

                $code = $codes->get($line->pay_code) ?? throw ValidationException::withMessages(['run' => __('Lönearten :code finns inte i lönen.', ['code' => $line->pay_code])]);
                $input = $this->runs->addInput($user, $run, [
                    'employee_id' => $line->employee_id,
                    'pay_code_id' => $code,
                    'quantity' => $line->quantity === null ? null : rtrim(rtrim($line->quantity, '0'), '.'),
                    'amount' => $line->amount_ore === null ? null : sprintf('%d.%02d', intdiv($line->amount_ore, 100), $line->amount_ore % 100),
                    'note' => $late ? __('Personalmat (Kök), avser :period', ['period' => $line->period]) : __('Personalmat :period (Kök)', ['period' => $line->period]),
                ]);

                $line->forceFill(['status' => MealPayrollProposal::APPROVED, 'payroll_run_id' => $run->id, 'payroll_input_id' => $input->id, 'decided_by' => $user->id, 'decided_at' => now()])->save();
            }

            Audit::record('kitchen.meal_benefits_approved', $run, ['proposal_ids' => $lines->pluck('id')->all(), 'replaced_ids' => $replaced->pluck('id')->all()], $company, $user);

            return $lines->count();
        }));
    }

    /** Whether the month's regular run is approved, paid or posted: later additions go into a later run. */
    private static function periodClosed(string $period): bool
    {
        return PayrollRun::query()->where('period', $period)->where('type', 'REGULAR')->whereIn('status', [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED])->exists();
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
