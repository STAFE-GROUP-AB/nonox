<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

use App\Models\Company;
use App\Models\SalesDocument;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Manufacturing\Domain\LockKind;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\Engine\FreeTime;
use App\Modules\Manufacturing\Planning\Engine\MaterialPlanner;
use App\Modules\Manufacturing\Planning\Engine\PlanInput;
use App\Modules\Manufacturing\Planning\Engine\PlanOperation;
use App\Modules\Manufacturing\Planning\Engine\PlanOrder;
use App\Modules\Manufacturing\Planning\Engine\PlanPerson;
use App\Modules\Manufacturing\Planning\Engine\PlanPin;
use App\Modules\Manufacturing\Planning\Engine\PlanSplit;
use App\Modules\Manufacturing\Planning\Engine\PlanWorkstation;
use App\Modules\Manufacturing\Planning\Engine\WeekPattern;
use App\Modules\ModuleManager;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loads everything one plan is computed from (ADR-071 §13.3 step 1) in a
 * fixed number of queries, whatever the number of orders: the open
 * production orders (released, started, and drafts tied to an open customer
 * order) with their operations, the minutes reported, the locks, the
 * material each still needs with stock and incoming purchase order rows,
 * the people with their working time, skills, overtime and absence, and the
 * workstations with their downtime.
 *
 * Absence is read through one narrow query that selects the employee id,
 * the dates and the extent of approved absences, and never the type or the
 * note (§13.7): the plan cannot know why someone is away.
 *
 * A scenario's changes (§13.6) are applied in memory: overtime, a move to
 * another person, a split, a priority. Nothing is written.
 */
final class PlanSnapshot
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  list<array<string, mixed>>  $changes  a scenario's changes
     */
    public function load(Company $company, CarbonImmutable $now, array $changes = []): PlanData
    {
        $settings = PlanningSettings::for($company);
        $now = $now->setTimezone(Clock::ZONE)->startOfMinute();
        $horizon = $now->addWeeks($settings->horizonWeeks());
        $today = $now->toDateString();

        $orders = $this->orders($company);
        $orderIds = array_values($orders->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $operations = $this->operations($company, $orderIds);
        $operationIds = array_values($operations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $reported = $this->reported($company, $operationIds);
        $locks = $this->locks($company, $operationIds)->groupBy('production_order_operation_id');
        $serving = $this->serving($company);

        [$people, $absentDays] = $this->people($company, $settings, $now, $horizon, $changes);
        $workstations = $this->workstations($company, $now, $horizon);

        $priorities = [];
        $assigns = [];
        $splits = [];

        foreach ($changes as $change) {
            match ($change['type'] ?? null) {
                'PRIORITY' => $priorities[(int) $change['production_order_id']] = isset($change['priority']) ? (int) $change['priority'] : null,
                'ASSIGN' => $assigns[(int) $change['order_operation_id']] = (int) $change['employee_id'],
                'SPLIT' => $splits[(int) $change['order_operation_id']] = [(int) $change['employee_id'], (int) $change['minutes']],
                default => null,
            };
        }

        $frozen = $this->frozen($company, $settings, $now, $operationIds);
        $byOrder = $operations->groupBy('production_order_id');
        $drafts = [];

        foreach ($orders as $order) {
            $id = (int) $order['id'];
            $planOperations = [];

            foreach ($byOrder->get($id, collect()) as $operation) {
                $operationId = (int) $operation['id'];
                $remaining = $operation['remaining_minutes_override'] !== null
                    ? (int) $operation['remaining_minutes_override']
                    : max(0, (int) $operation['planned_minutes'] - ($reported[$operationId] ?? 0));
                $workstationIds = $operation['workstation_id'] !== null && isset($workstations[(int) $operation['workstation_id']])
                    ? [(int) $operation['workstation_id']]
                    : array_values(array_filter($serving[(int) $operation['operation_id']] ?? [], fn (int $workstation): bool => isset($workstations[$workstation])));

                [$pin, $split] = $this->holds($locks->get($operationId, collect()), $frozen[$operationId] ?? null);

                if (isset($assigns[$operationId])) {
                    $pin = new PlanPin(0, $assigns[$operationId]);
                }

                if (isset($splits[$operationId])) {
                    $split = new PlanSplit(0, $splits[$operationId][0], $splits[$operationId][1]);
                }

                $planOperations[] = new PlanOperation(
                    $operationId,
                    (int) $operation['operation_id'],
                    $remaining,
                    $operation['required_skill_id'] === null ? null : (int) $operation['required_skill_id'],
                    $workstationIds,
                    (int) ($operation['wait_minutes'] ?? $operation['default_wait_minutes'] ?? 0),
                    $pin,
                    $split,
                );
            }

            $drafts[$id] = new PlanOrder(
                $id,
                (int) $order['number'],
                $planOperations,
                priority: array_key_exists($id, $priorities) ? $priorities[$id] : ($order['plan_priority'] === null ? null : (int) $order['plan_priority']),
                promisedOn: self::promised($order),
                releasedAt: $order['released_at'] === null ? null : Clock::minute(CarbonImmutable::parse((string) $order['released_at'])),
                salesDocumentId: $order['sales_document_id'] === null ? null : (string) $order['sales_document_id'],
            );
        }

        $sorted = array_values($drafts);
        usort($sorted, PlanOrder::compare(...));
        $ready = $this->material($company, $settings, $orderIds, array_map(fn (PlanOrder $order): int => $order->id, $sorted), $today);

        $planOrders = [];

        foreach ($orders as $order) {
            $draft = $drafts[(int) $order['id']];
            [$readyOn, $assumed] = $ready[$draft->id] ?? [null, false];
            $earliest = max(
                $readyOn === null ? PHP_INT_MIN : Clock::local($readyOn, 0),
                $order['planned_start_on'] === null ? PHP_INT_MIN : Clock::local((string) $order['planned_start_on'], 0),
            );

            $planOrders[] = new PlanOrder(
                $draft->id,
                $draft->number,
                $draft->operations,
                $draft->priority,
                $draft->promisedOn,
                $draft->releasedAt,
                $earliest === PHP_INT_MIN ? null : $earliest,
                $readyOn,
                $assumed,
                $draft->salesDocumentId,
            );
        }

        $input = new PlanInput(
            Clock::minute($now),
            Clock::minute($horizon),
            $settings->calendar(),
            array_values($people),
            array_map(fn (array $intervals, int $id): PlanWorkstation => new PlanWorkstation($id, $intervals), $workstations, array_keys($workstations)),
            $planOrders,
            $settings->deliveryBufferDays(),
        );

        return new PlanData(
            $input,
            $orders->mapWithKeys(fn (array $order): array => [(int) $order['id'] => $order['customer_id'] === null ? null : (int) $order['customer_id']])->all(),
            $absentDays,
        );
    }

    /**
     * The people's free working time and the days they are away, for the
     * resource view: working week (or Planering's published shifts) less
     * absence, plus overtime.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{0: array<int, PlanPerson>, 1: array<int, list<string>>}
     */
    public function people(Company $company, PlanningSettings $settings, CarbonImmutable $from, CarbonImmutable $to, array $changes = []): array
    {
        $employees = DB::table('employees')
            ->where('employees.company_id', $company->id)
            ->where(fn (Builder $query) => $query
                ->whereExists(fn (Builder $exists) => $exists->from('production_work_patterns')->whereColumn('production_work_patterns.employee_id', 'employees.id'))
                ->orWhereExists(fn (Builder $exists) => $exists->from('production_employee_skills')->whereColumn('production_employee_skills.employee_id', 'employees.id')))
            ->where(fn (Builder $query) => $query
                ->whereNotExists(fn (Builder $exists) => $exists->from('employments')->whereColumn('employments.employee_id', 'employees.id'))
                ->orWhereExists(fn (Builder $exists) => $exists->from('employments')->whereColumn('employments.employee_id', 'employees.id')
                    ->where(fn (Builder $active) => $active->whereNull('employments.end_date')->orWhere('employments.end_date', '>=', $from->toDateString()))))
            ->orderBy('employees.id')
            ->pluck('employees.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($employees === []) {
            return [[], []];
        }

        $skills = DB::table('production_employee_skills')->where('company_id', $company->id)->whereIn('employee_id', $employees)->get(['employee_id', 'skill_id'])->map(fn (object $record): array => get_object_vars($record))->groupBy('employee_id');
        $intervals = array_fill_keys($employees, []);

        if ($this->modules->isEnabled(PlanningModule::KEY, $company)) {
            $shifts = DB::table('planning_shifts')
                ->where('company_id', $company->id)
                ->where('status', Shift::PUBLISHED)
                ->whereIn('employee_id', $employees)
                ->where('ends_at', '>', $from)
                ->where('starts_at', '<', $to)
                ->get(['employee_id', 'starts_at', 'ends_at', 'break_minutes'])->map(fn (object $record): array => get_object_vars($record));

            foreach ($shifts as $shift) {
                $start = Clock::minute(CarbonImmutable::parse((string) $shift['starts_at']));
                $end = Clock::minute(CarbonImmutable::parse((string) $shift['ends_at']));
                $break = min((int) $shift['break_minutes'], $end - $start);
                // A shift's break has no time of its own: it is placed in the middle of the shift.
                $middle = $start + intdiv($end - $start - $break, 2);
                array_push($intervals[(int) $shift['employee_id']], [$start, $middle], [$middle + $break, $end]);
            }
        } else {
            $patterns = DB::table('production_work_patterns')->where('company_id', $company->id)->whereIn('employee_id', $employees)->get()->map(fn (object $record): array => get_object_vars($record))->groupBy('employee_id');
            $companyWeek = $settings->week();

            foreach ($employees as $employee) {
                $rows = $patterns->get($employee);
                $week = $rows === null ? $companyWeek : new WeekPattern($rows->mapWithKeys(fn (array $row): array => [
                    (int) $row['weekday'] => WeekPattern::day((int) Clock::minuteOfDay((string) $row['start_time']), (int) Clock::minuteOfDay((string) $row['end_time']), Clock::minuteOfDay($row['break_start'] === null ? null : (string) $row['break_start']), (int) $row['break_minutes']),
                ])->all());

                $intervals[$employee] = $week->intervals($from, $to);
            }
        }

        $overtime = DB::table('production_overtimes')->where('company_id', $company->id)->whereIn('employee_id', $employees)->where('ends_at', '>', $from)->where('starts_at', '<', $to)->get(['employee_id', 'starts_at', 'ends_at'])->map(fn (object $record): array => get_object_vars($record));

        foreach ($overtime as $row) {
            $intervals[(int) $row['employee_id']][] = [Clock::minute(CarbonImmutable::parse((string) $row['starts_at'])), Clock::minute(CarbonImmutable::parse((string) $row['ends_at']))];
        }

        foreach ($changes as $change) {
            if (($change['type'] ?? null) === 'OVERTIME' && isset($intervals[(int) $change['employee_id']])) {
                $intervals[(int) $change['employee_id']][] = [Clock::minute(CarbonImmutable::parse((string) $change['starts_at'], Clock::ZONE)), Clock::minute(CarbonImmutable::parse((string) $change['ends_at'], Clock::ZONE))];
            }
        }

        // The narrow absence query (§13.7): employee, dates and extent of approved absences, never the type or the note.
        $absences = DB::table('absences')
            ->where('company_id', $company->id)
            ->where('status', 'APPROVED')
            ->whereIn('employee_id', $employees)
            ->where('date_to', '>=', $from->toDateString())
            ->where('date_from', '<=', $to->toDateString())
            ->get(['employee_id', 'date_from', 'date_to', 'extent_bp', 'hours'])->map(fn (object $record): array => get_object_vars($record));

        $people = [];
        $absent = [];

        foreach ($employees as $employee) {
            $free = new FreeTime($intervals[$employee]);

            foreach ($absences->where('employee_id', $employee) as $absence) {
                $absent[$employee] = [...($absent[$employee] ?? []), ...Availability::removeAbsence($free, (string) $absence['date_from'], (string) $absence['date_to'], (int) $absence['extent_bp'], $absence['hours'] === null ? null : (float) $absence['hours'], $from, $to)];
            }

            $people[$employee] = new PlanPerson($employee, $free->intervals(), array_values($skills->get($employee, collect())->pluck('skill_id')->map(fn ($id): int => (int) $id)->all()));
        }

        return [$people, array_map(fn (array $days): array => array_values(array_unique($days)), $absent)];
    }

    /**
     * Active workstations: open whenever a person works them, less downtime.
     *
     * @return array<int, list<array{0: int, 1: int}>>
     */
    public function workstations(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = array_values(DB::table('production_workstations')->where('company_id', $company->id)->whereNull('archived_at')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());

        if ($ids === []) {
            return [];
        }

        $downtimes = DB::table('production_downtimes')
            ->where('company_id', $company->id)
            ->whereIn('workstation_id', $ids)
            ->where('starts_at', '<', $to)
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $from))
            ->get(['workstation_id', 'starts_at', 'ends_at'])->map(fn (object $record): array => get_object_vars($record));

        $workstations = [];

        foreach ($ids as $id) {
            $free = new FreeTime([[Clock::minute($from->startOfDay()), Clock::minute($to)]]);
            $free->remove(array_values($downtimes->where('workstation_id', $id)->map(fn (array $down): array => [
                Clock::minute(CarbonImmutable::parse((string) $down['starts_at'])),
                $down['ends_at'] === null ? Clock::minute($to) : Clock::minute(CarbonImmutable::parse((string) $down['ends_at'])),
            ])->all()));
            $workstations[$id] = $free->intervals();
        }

        return $workstations;
    }

    /** @return Collection<int, array<mixed>> */
    private function orders(Company $company): Collection
    {
        return DB::table('production_orders')
            ->leftJoin('sales_documents', fn ($join) => $join->on('sales_documents.id', '=', 'production_orders.sales_document_id')->where('sales_documents.company_id', $company->id))
            ->where('production_orders.company_id', $company->id)
            ->where(fn (Builder $query) => $query
                ->whereIn('production_orders.status', [ProductionOrderStatus::Released->value, ProductionOrderStatus::Started->value])
                ->orWhere(fn (Builder $draft) => $draft
                    ->where('production_orders.status', ProductionOrderStatus::Draft->value)
                    ->where('sales_documents.kind', SalesDocument::ORDER)
                    ->where('sales_documents.status', 'OPEN')))
            ->orderBy('production_orders.id')
            ->get([
                'production_orders.id', 'production_orders.number', 'production_orders.plan_priority', 'production_orders.released_at',
                'production_orders.planned_start_on', 'production_orders.due_on', 'production_orders.sales_document_id',
                'sales_documents.kind as sales_kind', 'sales_documents.until_date as sales_until', 'sales_documents.confirmed_delivery_on as sales_confirmed',
                'sales_documents.customer_id',
            ])->map(fn (object $record): array => get_object_vars($record));
    }

    /**
     * @param  list<int>  $orderIds
     * @return Collection<int, array<mixed>>
     */
    private function operations(Company $company, array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return DB::table('production_order_operations')
            ->join('operations', 'operations.id', '=', 'production_order_operations.operation_id')
            ->where('production_order_operations.company_id', $company->id)
            ->whereIn('production_order_operations.production_order_id', $orderIds)
            ->orderBy('production_order_operations.production_order_id')
            ->orderBy('production_order_operations.position')
            ->orderBy('production_order_operations.id')
            ->get([
                'production_order_operations.id', 'production_order_operations.production_order_id', 'production_order_operations.operation_id',
                'production_order_operations.planned_minutes', 'production_order_operations.workstation_id',
                'production_order_operations.remaining_minutes_override', 'production_order_operations.wait_minutes',
                'operations.required_skill_id', 'operations.wait_minutes as default_wait_minutes',
            ])->map(fn (object $record): array => get_object_vars($record));
    }

    /**
     * @param  list<int>  $operationIds
     * @return array<int, int> minutes reported per order operation
     */
    private function reported(Company $company, array $operationIds): array
    {
        if ($operationIds === []) {
            return [];
        }

        return DB::table('production_labour_entries')
            ->where('company_id', $company->id)
            ->whereIn('production_order_operation_id', $operationIds)
            ->groupBy('production_order_operation_id')
            ->selectRaw('production_order_operation_id, SUM(minutes) AS minutes')
            ->pluck('minutes', 'production_order_operation_id')
            ->mapWithKeys(fn ($minutes, $id): array => [(int) $id => (int) $minutes])
            ->all();
    }

    /**
     * @param  list<int>  $operationIds
     * @return Collection<int, array<mixed>>
     */
    private function locks(Company $company, array $operationIds): Collection
    {
        if ($operationIds === []) {
            return collect();
        }

        return DB::table('production_plan_locks')->where('company_id', $company->id)->whereIn('production_order_operation_id', $operationIds)->orderBy('id')->get()->map(fn (object $record): array => get_object_vars($record));
    }

    /** @return array<int, list<int>> operation id → active workstations that serve it */
    private function serving(Company $company): array
    {
        return DB::table('production_workstation_operations')
            ->join('production_workstations', 'production_workstations.id', '=', 'production_workstation_operations.workstation_id')
            ->where('production_workstation_operations.company_id', $company->id)
            ->whereNull('production_workstations.archived_at')
            ->orderBy('production_workstation_operations.workstation_id')
            ->get(['production_workstation_operations.operation_id', 'production_workstation_operations.workstation_id'])->map(fn (object $record): array => get_object_vars($record))
            ->groupBy('operation_id')
            ->map(fn (Collection $rows): array => array_values($rows->pluck('workstation_id')->map(fn ($id): int => (int) $id)->all()))
            ->all();
    }

    /**
     * Inside the frozen window the plan keeps what the latest run had (§13.6):
     * those slots act as locks.
     *
     * @param  list<int>  $operationIds
     * @return array<int, array<string, mixed>> order operation id → slot
     */
    private function frozen(Company $company, PlanningSettings $settings, CarbonImmutable $now, array $operationIds): array
    {
        if ($settings->frozenDays() === 0 || $operationIds === []) {
            return [];
        }

        $until = $settings->calendar()->addWorkingDays($now->startOfDay(), $settings->frozenDays())->endOfDay();
        $run = DB::table('production_plan_runs')->where('company_id', $company->id)->max('id');

        if ($run === null) {
            return [];
        }

        return DB::table('production_plan_slots')
            ->where('company_id', $company->id)
            ->where('run_id', $run)
            ->where('part', 0)
            ->whereIn('production_order_operation_id', $operationIds)
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $until)
            ->whereNotNull('employee_id')
            ->get(['production_order_operation_id', 'employee_id', 'workstation_id', 'starts_at'])->map(fn (object $record): array => get_object_vars($record))
            ->keyBy('production_order_operation_id')
            ->all();
    }

    /**
     * @param  Collection<int, array<mixed>>  $locks
     * @param  ?array<string, mixed>  $frozen
     * @return array{0: ?PlanPin, 1: ?PlanSplit}
     */
    private function holds(Collection $locks, ?array $frozen): array
    {
        $pin = null;
        $split = null;

        foreach ($locks as $lock) {
            $kind = LockKind::from((string) $lock['kind']);

            if ($kind === LockKind::Split) {
                $split = new PlanSplit((int) $lock['id'], (int) $lock['employee_id'], (int) $lock['minutes']);

                continue;
            }

            if ($kind === LockKind::Lock || $pin === null) {
                $pin = new PlanPin(
                    (int) $lock['id'],
                    (int) $lock['employee_id'],
                    $lock['workstation_id'] === null ? null : (int) $lock['workstation_id'],
                    $kind === LockKind::Lock ? Clock::minute(CarbonImmutable::parse((string) $lock['starts_at'])) : null,
                );
            }
        }

        if (($pin === null || ! $pin->isLock()) && $frozen !== null) {
            $pin = new PlanPin(0, (int) $frozen['employee_id'], $frozen['workstation_id'] === null ? null : (int) $frozen['workstation_id'], Clock::minute(CarbonImmutable::parse((string) $frozen['starts_at'])));
        }

        return [$pin, $split];
    }

    /**
     * The material ready date per order (§13.3 step 2), from the components
     * each still needs, the stock, the incoming purchase order rows and the
     * suppliers' lead times.
     *
     * @param  list<int>  $orderIds
     * @param  list<int>  $planningOrder
     * @return array<int, array{0: ?string, 1: bool}>
     */
    private function material(Company $company, PlanningSettings $settings, array $orderIds, array $planningOrder, string $today): array
    {
        if ($orderIds === []) {
            return [];
        }

        $components = DB::table('production_order_components')
            ->join('articles', 'articles.id', '=', 'production_order_components.component_article_id')
            ->where('production_order_components.company_id', $company->id)
            ->whereIn('production_order_components.production_order_id', $orderIds)
            ->where('articles.is_stock_item', true)
            ->whereColumn('production_order_components.issued_milli', '<', 'production_order_components.required_milli')
            ->get(['production_order_components.production_order_id', 'production_order_components.component_article_id', 'production_order_components.required_milli', 'production_order_components.issued_milli', 'articles.default_supplier_id'])->map(fn (object $record): array => get_object_vars($record));

        if ($components->isEmpty()) {
            return [];
        }

        $articles = array_values($components->pluck('component_article_id')->map(fn ($id): int => (int) $id)->unique()->all());
        $needs = [];

        foreach ($components as $component) {
            $needs[(int) $component['production_order_id']][(int) $component['component_article_id']] = ($needs[(int) $component['production_order_id']][(int) $component['component_article_id']] ?? 0) + (int) $component['required_milli'] - (int) $component['issued_milli'];
        }

        $stock = DB::table('stock_levels')->where('company_id', $company->id)->whereIn('article_id', $articles)->groupBy('article_id')->selectRaw('article_id, SUM(quantity_milli) AS quantity')
            ->pluck('quantity', 'article_id')->mapWithKeys(fn ($quantity, $id): array => [(int) $id => (int) $quantity])->all();

        $incoming = DB::table('purchase_order_rows')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_rows.purchase_order_id')
            ->where('purchase_order_rows.company_id', $company->id)
            ->whereIn('purchase_order_rows.article_id', $articles)
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->whereColumn('purchase_order_rows.received_milli', '<', 'purchase_order_rows.quantity_milli')
            ->get(['purchase_order_rows.article_id', 'purchase_order_rows.quantity_milli', 'purchase_order_rows.received_milli', 'purchase_order_rows.expected_on', 'purchase_orders.requested_delivery_on'])->map(fn (object $record): array => get_object_vars($record))
            ->groupBy('article_id')
            ->map(fn (Collection $rows): array => array_values($rows->map(fn (array $row): array => [
                ($row['expected_on'] ?? $row['requested_delivery_on']) === null ? null : (string) ($row['expected_on'] ?? $row['requested_delivery_on']),
                (int) $row['quantity_milli'] - (int) $row['received_milli'],
            ])->all()))
            ->mapWithKeys(fn (array $rows, $id): array => [(int) $id => $rows])
            ->all();

        $supplierDays = $this->supplierLeadDays($company, $today);
        $leadDays = [];

        foreach ($components as $component) {
            $supplier = $component['default_supplier_id'] === null ? null : (int) $component['default_supplier_id'];

            if ($supplier !== null && isset($supplierDays[$supplier])) {
                $leadDays[(int) $component['component_article_id']] = $supplierDays[$supplier];
            }
        }

        return MaterialPlanner::allocate($planningOrder, $needs, $stock, $incoming, $leadDays, $today, $settings->leadTimeDays());
    }

    /**
     * Each supplier's median days from sent to received over the last twelve
     * months, from purchase orders received in full (§10). The day the order
     * was last updated stands for the day the last goods came.
     *
     * @return array<int, int>
     */
    private function supplierLeadDays(Company $company, string $today): array
    {
        $rows = DB::table('purchase_orders')
            ->where('company_id', $company->id)
            ->where('status', PurchaseOrderStatus::Received->value)
            ->whereNotNull('sent_at')
            ->where('updated_at', '>=', CarbonImmutable::parse($today)->subYear())
            ->get(['supplier_id', 'sent_at', 'updated_at'])->map(fn (object $record): array => get_object_vars($record));

        return $rows->groupBy('supplier_id')
            ->mapWithKeys(fn (Collection $orders, $supplier): array => [(int) $supplier => (int) ceil((float) $orders->map(fn (array $order): int => max(0, (int) CarbonImmutable::parse((string) $order['sent_at'])->startOfDay()->diffInDays(CarbonImmutable::parse((string) $order['updated_at'])->startOfDay())))->median())])
            ->all();
    }

    /** The promised date: the date confirmed to the customer, else the order's delivery date, else the production order's due date. */
    /** @param  array<string, mixed>  $order */
    private static function promised(array $order): ?string
    {
        $date = $order['sales_confirmed']
            ?? ($order['sales_kind'] === SalesDocument::ORDER ? $order['sales_until'] : null)
            ?? $order['due_on'];

        return $date === null ? null : substr((string) $date, 0, 10);
    }
}
