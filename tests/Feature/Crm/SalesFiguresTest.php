<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\SalesDocument;
use App\Modules\Crm\Actions\CompleteActivity;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\SaveGoal;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\Domain\Forecast;
use App\Modules\Crm\Domain\GoalMetric;
use App\Modules\Crm\Domain\GoalProgress;
use App\Modules\Crm\Domain\PipelineFigures;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\LossReason;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The figures of CRM stage 2 from real rows (ADR-043): the board's sums per
 * stage, the open pipeline, win rate, sales cycle, activities per week, the
 * forecast, and goal progress measured from meetings, quotes and won deals.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->seller = Bookkeeping::member($this->company, 'sales');
    $this->account = Crm::prospect($this->company, $this->owner);
});

it('sums the open deals per stage, with the value weighted by the stage\'s probability', function () {
    Crm::deal($this->company, $this->owner, $this->account, 'A', 1_000_000);
    Crm::deal($this->company, $this->owner, $this->account, 'B', 333, ['stage_id' => Crm::stage(2)->id]);
    Crm::deal($this->company, $this->owner, $this->account, 'C', 200_000, ['stage_id' => Crm::stage(2)->id, 'owner_id' => $this->seller->id]);
    $lost = Crm::deal($this->company, $this->owner, $this->account, 'D', 5_000_000);
    app(LoseDeal::class)($this->owner, $this->company, $lost, ['loss_reason_id' => LossReason::query()->value('id')]);

    $figures = app(PipelineFigures::class);
    $stages = $figures->stages(Crm::pipeline()->id);

    expect($stages[Crm::stage(0)->id])->toBe(['count' => 1, 'value' => 1_000_000, 'weighted' => 100_000])
        // (333 + 200 000) × 50 % = 100 166.5 öre, rounded half up.
        ->and($stages[Crm::stage(2)->id])->toBe(['count' => 2, 'value' => 200_333, 'weighted' => 100_167])
        ->and($figures->stages(Crm::pipeline()->id, $this->seller->id))->toBe([Crm::stage(2)->id => ['count' => 1, 'value' => 200_000, 'weighted' => 100_000]])
        ->and($figures->open())->toBe(['count' => 3, 'value' => 1_200_333, 'weighted' => 200_167]);
});

it('gives the share won over the period and the average days from created to won', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-01 10:00:00'));
    $early = Crm::deal($this->company, $this->owner, $this->account, 'Tidig');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00'));
    $late = Crm::deal($this->company, $this->owner, $this->account, 'Sen');
    $lost = Crm::deal($this->company, $this->owner, $this->account, 'Förlorad');
    $this->travelTo(CarbonImmutable::parse('2026-06-11 15:00:00'));
    app(WinDeal::class)($this->owner, $this->company, $early);
    app(WinDeal::class)($this->owner, $this->company, $late);
    app(LoseDeal::class)($this->owner, $this->company, $lost, ['loss_reason_id' => LossReason::query()->value('id')]);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    $figures = app(PipelineFigures::class);
    $from = CarbonImmutable::parse('2026-05-17');
    $now = CarbonImmutable::now();

    expect($figures->winRate($from, $now))->toMatchArray(['won' => 2, 'lost' => 1, 'won_value' => 2_000_000])
        ->and(round((float) $figures->winRate($from, $now)['rate'], 4))->toBe(0.6667)
        // 41 days and 10 days.
        ->and($figures->cycleDays($from, $now))->toBe(25.5)
        ->and($figures->winRate(CarbonImmutable::parse('2026-06-12'), $now)['rate'])->toBeNull()
        ->and($figures->cycleDays(CarbonImmutable::parse('2026-06-12'), $now))->toBeNull();
});

it('counts completed activities per week for the last eight weeks, Monday to Sunday', function () {
    $log = app(LogActivity::class);
    $this->travelTo(CarbonImmutable::parse('2026-06-08 09:00:00'));
    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'NOTE']);
    $this->travelTo(CarbonImmutable::parse('2026-06-14 23:30:00'));
    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'CALL', 'done' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 08:00:00'));
    $log($this->seller, $this->company, ['account_id' => $this->account->id, 'type' => 'NOTE']);
    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'TASK']);
    $this->travelTo(CarbonImmutable::parse('2026-04-10 09:00:00'));
    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'NOTE']);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    $weeks = app(PipelineFigures::class)->activitiesPerWeek(CarbonImmutable::now());

    expect($weeks)->toHaveCount(8)
        ->and($weeks[0]['start'])->toBe('2026-04-27')
        ->and(array_slice(array_column($weeks, 'count'), -2))->toBe([2, 1])
        ->and($weeks[7]['week'])->toBe('2026-W25')
        ->and(array_sum(array_column(app(PipelineFigures::class)->activitiesPerWeek(CarbonImmutable::now(), 8, $this->seller->id), 'count')))->toBe(1);
});

it('forecasts from the deals in the database, by owner', function () {
    Crm::deal($this->company, $this->owner, $this->account, 'Juli', 1_000_000, ['expected_close_date' => '2026-07-10', 'stage_id' => Crm::stage(3)->id]);
    Crm::deal($this->company, $this->owner, $this->account, 'Sen', 400_000, ['expected_close_date' => '2026-06-01', 'owner_id' => $this->seller->id]);
    $won = Crm::deal($this->company, $this->owner, $this->account, 'Vunnen', 250_000);
    app(WinDeal::class)($this->owner, $this->company, $won);

    $all = app(Forecast::class)->for(CarbonImmutable::now(), 3, 1);
    $mine = app(Forecast::class)->for(CarbonImmutable::now(), 3, 1, $this->seller->id);

    expect(collect($all['months'])->firstWhere('month', '2026-07'))->toMatchArray(['value' => 1_000_000, 'weighted' => 750_000])
        ->and(collect($all['months'])->firstWhere('month', '2026-06'))->toMatchArray(['won' => 250_000, 'won_count' => 1])
        ->and($all['overdue'])->toBe(['value' => 400_000, 'weighted' => 40_000, 'count' => 1])
        ->and($mine['overdue']['count'])->toBe(1)
        ->and(array_sum(array_column($mine['months'], 'value')))->toBe(0);
});

it('measures goals from completed meetings, quotes the seller made and deals the seller won, with what was invoiced on them', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Kunden AB');
    $account = Crm::accountFor($customer);
    $goal = app(SaveGoal::class);
    foreach (['MEETINGS' => '4', 'QUOTES' => '2', 'ORDER_VALUE' => '50 000'] as $metric => $target) {
        $goal($this->owner, $this->company, ['user_id' => $this->seller->id, 'month' => '2026-06', 'metric' => $metric, 'target' => $target]);
    }

    // Two meetings done in June (one open and one in May do not count), assigned to the seller.
    $log = app(LogActivity::class);
    $log($this->seller, $this->company, ['account_id' => $account->id, 'type' => 'MEETING', 'done' => true]);
    $open = $log($this->seller, $this->company, ['account_id' => $account->id, 'type' => 'MEETING']);
    app(CompleteActivity::class)($this->seller, $this->company, $open);
    $log($this->seller, $this->company, ['account_id' => $account->id, 'type' => 'MEETING']);
    $log($this->seller, $this->company, ['account_id' => $account->id, 'type' => 'CALL', 'done' => true]);
    Activity::query()->create(['account_id' => $account->id, 'type' => 'MEETING', 'subject' => 'Maj', 'assigned_to' => $this->seller->id, 'done_at' => '2026-05-20 10:00:00']);

    // One quote by the seller in June; the owner's does not count.
    $quoteBy = fn ($user) => app(SaveSalesDocument::class)($user, $this->company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-10', 'rows' => [['description' => 'Tjänst', 'quantity' => '1', 'price' => '30000', 'vat_rate' => 2500]]]);
    $owners = $quoteBy($this->owner);
    $this->actingAs($this->owner);

    // A won deal of the seller's, linked to the owner's quote, which is invoiced in June.
    $deal = Crm::deal($this->company, $this->owner, $account, 'Affären', 3_000_000, ['owner_id' => $this->seller->id]);
    app(WinDeal::class)($this->owner, $this->company, $deal, ['quote_id' => $owners->id]);
    $invoice = app(ProgressSalesDocument::class)->toInvoice($this->owner, $owners->fresh());
    app(IssueInvoice::class)($this->owner, $invoice);

    $goal($this->owner, $this->company, ['user_id' => $this->owner->id, 'month' => '2026-06', 'metric' => 'QUOTES', 'target' => '3']);
    $progress = collect(app(GoalProgress::class)->for(CarbonImmutable::parse('2026-06-01')))->keyBy(fn (array $row): string => ($row['user_id'] === $this->owner->id ? 'owner-' : '').$row['metric']->value);

    expect($progress['MEETINGS'])->toMatchArray(['target' => 4, 'actual' => 2, 'invoiced' => null])
        ->and($progress['QUOTES'])->toMatchArray(['target' => 2, 'actual' => 0])
        ->and($progress['owner-QUOTES'])->toMatchArray(['target' => 3, 'actual' => 1])
        ->and($progress['ORDER_VALUE'])->toMatchArray(['target' => 5_000_000, 'actual' => 3_000_000, 'invoiced' => 3_000_000])
        ->and($progress['ORDER_VALUE']['metric'])->toBe(GoalMetric::OrderValue);
});

it('returns nothing without goals, and only the asked sellers\' goals', function () {
    app(SaveGoal::class)($this->owner, $this->company, ['user_id' => $this->owner->id, 'month' => '2026-06', 'metric' => 'MEETINGS', 'target' => '1']);

    expect(app(GoalProgress::class)->for(CarbonImmutable::parse('2026-07-01')))->toBe([])
        ->and(app(GoalProgress::class)->for(CarbonImmutable::parse('2026-06-01'), [$this->seller->id]))->toBe([])
        ->and(app(GoalProgress::class)->for(CarbonImmutable::parse('2026-06-01'), [$this->owner->id]))->toHaveCount(1);
});
