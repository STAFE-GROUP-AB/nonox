<?php

declare(strict_types=1);

namespace App\Actions\Budget;

use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetStatus;
use App\Models\BudgetVersion;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Activates, locks and deletes budget versions. The active version of a
 * fiscal year and kind is the one the reports compare against; a locked
 * version is read-only for good (copy it to change it); deleting removes a
 * version and its lines, and is written to the audit log.
 *
 * TODO(legal-verify): whether a budget is räkenskapsinformation (BFL 1 kap.
 * 2 § första stycket 6). Built on the proposal in ADR-081: it is not, so a
 * person may delete a version; the deletion is audited. A budget used as a
 * basis for an assessment in the annual report (an impairment test, going
 * concern under K3) should be kept by the user. So a LOCKED version of a
 * closed fiscal year cannot be deleted.
 */
class ManageBudgetVersion
{
    public function activate(User $user, BudgetVersion $version): BudgetVersion
    {
        Gate::forUser($user)->authorize('manage', $version);

        return $this->inCompany($version, fn (): BudgetVersion => DB::transaction(function () use ($user, $version): BudgetVersion {
            BudgetVersion::query()
                ->where('fiscal_year_id', $version->fiscal_year_id)
                ->where('kind', $version->kind->value)
                ->whereKeyNot($version->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);

            $version->update(['is_active' => true]);

            Audit::record('budget.activated', $version, ['fiscal_year_id' => $version->fiscal_year_id, 'kind' => $version->kind->value], $version->company_id, $user);

            return $version;
        }));
    }

    public function lock(User $user, BudgetVersion $version): BudgetVersion
    {
        Gate::forUser($user)->authorize('update', $version);

        return $this->inCompany($version, fn (): BudgetVersion => DB::transaction(function () use ($user, $version): BudgetVersion {
            $version->update(['status' => BudgetStatus::Locked, 'locked_by' => $user->id, 'locked_at' => now()]);

            Audit::record('budget.locked', $version, ['fiscal_year_id' => $version->fiscal_year_id], $version->company_id, $user);

            return $version;
        }));
    }

    public function delete(User $user, BudgetVersion $version): void
    {
        Gate::forUser($user)->authorize('manage', $version);

        $this->inCompany($version, fn () => DB::transaction(function () use ($user, $version): void {
            $closed = FiscalYear::query()->whereKey($version->fiscal_year_id)->lockForUpdate()->value('status') === FiscalYearStatus::Closed;

            if ($version->isLocked() && $closed) {
                throw ValidationException::withMessages(['version' => $version->kind === BudgetKind::Forecast
                    ? __('En låst prognos för ett stängt räkenskapsår kan inte tas bort. Den kan ha legat till grund för bedömningar i årsredovisningen och ska sparas.')
                    : __('En låst budget för ett stängt räkenskapsår kan inte tas bort. Den kan ha legat till grund för bedömningar i årsredovisningen och ska sparas.')]);
            }

            $lines = $version->lines()->count();

            Audit::record('budget.deleted', $version, [
                'fiscal_year_id' => $version->fiscal_year_id,
                'kind' => $version->kind->value,
                'status' => $version->status->value,
                'lines' => $lines,
            ], $version->company_id, $user);

            // The lines go with the version (ON DELETE CASCADE); the trigger lets a locked version's lines go only that way.
            $version->delete();
        }));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inCompany(BudgetVersion $version, callable $callback): mixed
    {
        return CurrentCompany::run($version->company_id, $callback);
    }
}
