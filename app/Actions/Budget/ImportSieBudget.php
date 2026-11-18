<?php

declare(strict_types=1);

namespace App\Actions\Budget;

use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\Sie\SieBudgetAnalyser;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Step two of reading a budget from a SIE file (ADR-081 E4): the user has
 * read the report and confirms. The file is checked once more, since the
 * chart of accounts or the registers may have changed since step one, and
 * the budget becomes a new draft version. Nothing in the bookkeeping changes.
 *
 * The full SIE import (ImportSie) writes the budget of its file with
 * createVersion() inside its own transaction.
 */
class ImportSieBudget
{
    public function __construct(private readonly SieBudgetAnalyser $analyser) {}

    public function __invoke(User $user, Company $company, string $path, string $sha256): BudgetVersion
    {
        Gate::forUser($user)->authorize('create', [BudgetVersion::class, $company]);

        return CurrentCompany::run($company, function () use ($user, $company, $path, $sha256): BudgetVersion {
            $plan = $this->analyser->analyse($company, $path);

            if ($plan->blockers !== []) {
                throw ValidationException::withMessages(['file' => $plan->blockers]);
            }

            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->findOrFail($plan->fiscalYearId);

            return DB::transaction(fn (): BudgetVersion => self::createVersion($user, $company, $fiscalYear, $plan->lines, [
                'source' => 'sie_file',
                'sha256' => $sha256,
            ]));
        });
    }

    /**
     * A new draft budget version with the file's lines. It becomes the one
     * the reports use only when the year has none yet, as a new budget does.
     * Call inside a transaction, in the company's context.
     *
     * @param  list<array{account_id: int, cost_center_id: ?int, project_id: ?int, period: string, amount_ore: int}>  $lines
     * @param  array<string, mixed>  $audit  added to the audit entry
     */
    public static function createVersion(User $user, Company $company, FiscalYear $fiscalYear, array $lines, array $audit): BudgetVersion
    {
        // Two budgets created at once for the same year: the second waits here, so only the first becomes active.
        FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->first();

        $version = BudgetVersion::create([
            'fiscal_year_id' => $fiscalYear->id,
            'name' => __('Budget från SIE-fil'),
            'kind' => BudgetKind::Budget,
            'created_by' => $user->id,
            'is_active' => ! BudgetVersion::query()->where('fiscal_year_id', $fiscalYear->id)->where('kind', BudgetKind::Budget->value)->where('is_active', true)->exists(),
        ]);

        $count = BudgetLines::insert($version, $lines);

        Audit::record('budget.created', $version, [
            'fiscal_year_id' => $fiscalYear->id,
            'kind' => $version->kind->value,
            'start' => 'sie',
            'lines' => $count,
            ...$audit,
        ], $company, $user);

        return $version;
    }
}
