<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Manufacturing\Mrp\Models\MrpPlannedOrder;
use App\Modules\Manufacturing\Mrp\Models\MrpRun;
use App\Modules\Manufacturing\Mrp\MrpEngine;
use App\Modules\Manufacturing\Mrp\MrpSnapshot;
use App\Modules\Manufacturing\Mrp\MrpStructureLoop;
use App\Modules\Manufacturing\Mrp\PlannedOrderProposal;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kör behovsplanering" (ADR-081 gap 5): reads the company's articles,
 * orders and forecast, runs the engine and stores the run with its planned
 * orders. Nothing is ordered: the planned orders wait for a person. Runs
 * older than 30 days are deleted with their planned orders. Needs mrp:run.
 *
 * A fixed number of queries whatever the number of articles and orders,
 * except that the planned orders are inserted in chunks of 500: one more
 * insert per 500 planned orders.
 */
class RunMrp
{
    use GuardsInventory;

    private const int CHUNK = 500;

    public function __construct(
        private readonly MrpSnapshot $snapshot,
        private readonly MrpEngine $engine,
    ) {}

    public function __invoke(User $user, Company $company, int $weeks = 13, ?CarbonImmutable $today = null): MrpRun
    {
        $this->guard($user, $company, 'planRun');

        if ($weeks < 1 || $weeks > ForecastPeriodKind::Week->maxCount()) {
            throw ValidationException::withMessages(['weeks' => __('Planera mellan 1 och 52 veckor framåt.')]);
        }

        $today ??= CarbonImmutable::today();

        return CurrentCompany::run($company, fn (): MrpRun => DB::transaction(function () use ($user, $company, $weeks, $today): MrpRun {
            $input = $this->snapshot->read($company, $today, $weeks);

            try {
                $plan = $this->engine->plan($input);
            } catch (MrpStructureLoop $loop) {
                $numbers = Article::query()->whereKey($loop->articleIds)->orderBy('number')->pluck('number')->implode(', ');

                throw ValidationException::withMessages(['weeks' => __('Stycklistorna för :articles bildar en slinga, så de kan inte planeras. Rätta stycklistorna under Tillverkning.', ['articles' => $numbers])]);
            }

            $run = MrpRun::create([
                'starts_on' => $input->firstMonday->toDateString(),
                'horizon_weeks' => $input->weeks,
                'run_by' => $user->id,
                'inputs_hash' => $input->hash(),
                'engine_version' => MrpEngine::VERSION,
                'article_count' => count($input->articles),
                'planned_order_count' => count($plan->plannedOrders),
                'exception_count' => $plan->exceptionCount(),
                'message_count' => count($plan->messages),
                'messages' => $plan->messages,
            ]);

            $now = now();

            foreach (array_chunk($plan->plannedOrders, self::CHUNK) as $chunk) {
                MrpPlannedOrder::query()->insert(array_map(fn (PlannedOrderProposal $order): array => [
                    'company_id' => $company->id,
                    'mrp_run_id' => $run->id,
                    'article_id' => $order->articleId,
                    'kind' => $order->kind->value,
                    'quantity_milli' => $order->quantityMilli,
                    'release_on' => $order->releaseOn,
                    'due_on' => $order->dueOn,
                    'supplier_id' => $order->supplierId,
                    'pegging' => json_encode($order->pegging, JSON_THROW_ON_ERROR),
                    'exception' => $order->exception?->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            // Planned orders are kept only while they are current (ADR-081 gap 5): older runs go with their orders.
            MrpRun::query()->where('created_at', '<', $now->copy()->subDays(MrpRun::KEEP_DAYS))->delete();

            Audit::record('mrp.run', $run, [
                'horizon_weeks' => $run->horizon_weeks,
                'starts_on' => $run->starts_on->toDateString(),
                'articles' => $run->article_count,
                'planned_orders' => $run->planned_order_count,
                'exceptions' => $run->exception_count,
                'messages' => $run->message_count,
                'engine_version' => $run->engine_version,
            ], $company, $user);

            return $run;
        }));
    }
}
