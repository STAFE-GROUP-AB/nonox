<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\Consolidation;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAccessLevel;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupRun;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\DimensionScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Runs the consolidation of a shared group for a period and stores the run
 * (ADR-060, addendum 2026-11-07, 2.4; ADR-081 E24). Open to everyone who
 * sees the group: FULL access, or the group role while still a member of the
 * parent (CONSOLIDATED), because a run holds consolidated figures only. It
 * writes one row in the run log and one entry in the parent's audit log,
 * and nothing in any member company's books.
 *
 * The ledgers are read in one REPEATABLE READ snapshot, so a voucher posted
 * while the run reads cannot make the inputs and the output disagree.
 */
final class ConsolidateGroup
{
    public function __construct(private readonly Consolidation $consolidation) {}

    /**
     * @param  array<string, mixed>  $input  from and to (Y-m-d)
     *
     * @throws ValidationException
     * @throws ModelNotFoundException when the user does not see the group
     */
    public function __invoke(User $user, int $groupId, array $input): CorporateGroupRun
    {
        $access = GroupAccess::for($user);
        $group = $access->corporateGroup($groupId);
        [$from, $to] = self::period($input);
        $level = self::authorize($access, $group, $from);

        $result = self::snapshot(fn (): array => $this->consolidation->compute($access, $group, $from, $to));
        self::refuseTooFew($level, $result['inputs']);

        return DB::transaction(function () use ($user, $group, $from, $to, $result): CorporateGroupRun {
            $run = CorporateGroupRun::create([
                'corporate_group_id' => $group->id,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'run_by' => $user->id,
                'inputs' => $result['inputs'],
                'inputs_hash' => Consolidation::hash($result['inputs']),
                'output' => $result['output'],
                'output_hash' => Consolidation::hash($result['output']),
                'preliminary' => $result['preliminary'],
            ]);

            // Not bookkeeping in any company: the parent's log says that the group was consolidated, with counts only.
            Audit::record('corporate_group.consolidated', $group, [
                'corporate_group_id' => $group->id,
                'run_id' => $run->id,
                'companies' => count($result['inputs']['companies']),
                'excluded' => count($result['inputs']['excluded']),
                'preliminary' => $result['preliminary'],
            ], $group->parent_company_id, $user);

            return $run;
        });
    }

    /**
     * Recomputes a stored run's inputs, and, when they are unchanged, its
     * output: whether the figures can still be reproduced from the books.
     *
     * @return array{inputs_unchanged: bool, output_reproduced: ?bool, stored_intact: bool}
     */
    public function check(User $user, int $groupId, int $runId): array
    {
        $access = GroupAccess::for($user);
        $group = $access->corporateGroup($groupId);
        $run = CorporateGroupRun::query()->where('corporate_group_id', $group->id)->findOrFail($runId);
        self::refuseTooFew(self::authorize($access, $group, $run->period_from), $run->inputs);

        $result = self::snapshot(fn (): array => $this->consolidation->compute($access, $group, $run->period_from, $run->period_to));
        $inputsUnchanged = hash_equals($run->inputs_hash, Consolidation::hash($result['inputs']));

        return [
            'inputs_unchanged' => $inputsUnchanged,
            'output_reproduced' => $inputsUnchanged ? hash_equals($run->output_hash, Consolidation::hash($result['output'])) : null,
            'stored_intact' => $run->intact(),
        ];
    }

    /**
     * Who sees the group for the period. A member limited to cost centres or
     * projects in the parent sees nothing of the group (ADR-081 gap 10), even
     * with the group role.
     */
    public static function level(GroupAccess $access, CorporateGroup $group, ?CarbonImmutable $from = null): GroupAccessLevel
    {
        $level = $access->levelFor($group, $from);

        if ($level === GroupAccessLevel::Consolidated && DimensionScope::limits($access->user, $group->parent_company_id)) {
            return GroupAccessLevel::None;
        }

        return $level;
    }

    /**
     * Whether a run may be shown to someone who sees only the group's sums
     * (CONSOLIDATED): at least two companies, none left out, and every one
     * included for the whole period with its balance sheet. A run with one
     * company is that company's figures; a run where a company is left out,
     * or cut at an acquisition or disposal, can be subtracted from another
     * run into one company's figures (cold review E24, P1-1 and its
     * re-check). What remains is the difference between periods in which
     * every member is fully included: comparing periods, and comparing a run
     * before a disposal with one after it, which gives the disposed company's
     * balance sheet as any group report before and after a disposal does.
     * ADR-060 2.2 accepted inference for viewers who also see the parent; the
     * S2-1.4 as-built addendum extends that acceptance to these period and
     * disposal differences (Lead decision). FULL access sees every run.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function shownToConsolidated(array $inputs): bool
    {
        $companies = $inputs['companies'] ?? [];
        $from = $inputs['period']['from'] ?? null;
        $to = $inputs['period']['to'] ?? null;

        if (count($companies) < self::MINIMUM_COMPANIES || ($inputs['excluded'] ?? []) !== []) {
            return false;
        }

        // No member may be cut: every one counts from the first day to the last, with its balance sheet.
        return array_all($companies, fn (array $company): bool => ($company['balance'] ?? false) === true
            && ($company['income_from'] ?? null) === $from
            && ($company['income_to'] ?? null) === $to);
    }

    /** At least this many companies must contribute before a consolidated viewer sees a run. */
    public const int MINIMUM_COMPANIES = 2;

    /**
     * @param  array<string, mixed>  $inputs
     *
     * @throws ValidationException
     */
    private static function refuseTooFew(GroupAccessLevel $level, array $inputs): void
    {
        if ($level === GroupAccessLevel::Consolidated && ! self::shownToConsolidated($inputs)) {
            throw ValidationException::withMessages(['period' => __('Den här körningen kan inte visas för den som bara ser koncernens summor, eftersom den har färre än två företag eller något företag har lämnats utanför eller bara ingår en del av perioden.')]);
        }
    }

    private static function authorize(GroupAccess $access, CorporateGroup $group, CarbonImmutable $from): GroupAccessLevel
    {
        $level = self::level($access, $group, $from);

        if (! $level->sees()) {
            throw (new ModelNotFoundException)->setModel(CorporateGroup::class, [$group->id]);
        }

        return $level;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function period(array $input): array
    {
        $data = Validator::make($input, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'from.required' => __('Ange periodens första dag.'),
            'from.date_format' => __('Ange periodens första dag som ÅÅÅÅ-MM-DD.'),
            'to.required' => __('Ange periodens sista dag.'),
            'to.date_format' => __('Ange periodens sista dag som ÅÅÅÅ-MM-DD.'),
            'to.after_or_equal' => __('Periodens sista dag kan inte vara före den första.'),
        ])->validate();

        return [CarbonImmutable::parse($data['from'])->startOfDay(), CarbonImmutable::parse($data['to'])->startOfDay()];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    public static function snapshot(callable $read, ?string $connection = null): mixed
    {
        $db = DB::connection($connection);

        // Inside an outer transaction (tests) the isolation level can no longer be set: the caller's transaction decides.
        if ($db->transactionLevel() > 0) {
            return $read();
        }

        return $db->transaction(function () use ($db, $read): mixed {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $read();
        });
    }
}
