<?php

declare(strict_types=1);

use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SaveGoal;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\ActivitiesPage;
use App\Modules\Crm\Livewire\Dashboard;
use App\Modules\Crm\Livewire\DealCard;
use App\Modules\Crm\Livewire\DealsBoard;
use App\Modules\Crm\Livewire\ForecastPage;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Models\LossReason;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The pages of CRM stage 2 (ADR-043): the board, a deal's card, the
 * dashboard and Prognos och mål, with the mandatory next step on every
 * screen where a step is completed, Swedish URLs, permissions per role and
 * a fixed number of queries.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner, 'Prospektet AB');
});

/** The queries of rendering $component, warm. */
function queriesFor(string $component, array $params = []): int
{
    Livewire::test($component, $params);
    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test($component, $params);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $queries;
}

dataset('crm deal roles', [
    'ägare' => ['owner', ['viewAny', 'manage', 'settings']],
    'administratör' => ['admin', ['viewAny', 'manage', 'settings']],
    'redovisningskonsult' => ['accountant', ['viewAny', 'manage', 'settings']],
    'bokförare' => ['bookkeeper', ['viewAny', 'manage', 'settings']],
    'revisor' => ['auditor', ['viewAny']],
    'attestant' => ['approver', []],
    'säljare' => ['sales', ['viewAny', 'manage']],
]);

it('allows and denies deals, pipelines and goals per role', function (string $role, array $allowed) {
    $user = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
    $gate = Gate::forUser($user);

    foreach ([Deal::class, Goal::class] as $model) {
        expect(array_values(array_filter(['viewAny', 'manage', 'settings'], fn (string $ability): bool => $gate->allows($ability, [$model, $this->company]))))->toBe($allowed);
    }

    $deal = Crm::deal($this->company, $this->owner, $this->account);
    expect($gate->allows('view', $deal))->toBe(in_array('viewAny', $allowed, true))
        ->and($gate->allows('update', $deal))->toBe(in_array('manage', $allowed, true))
        ->and($gate->allows('configure', Crm::pipeline()))->toBe(in_array('settings', $allowed, true));

    $this->actingAs($user);
    $this->get(route('crm.deals'))->assertStatus(in_array('viewAny', $allowed, true) ? 200 : 403);
    $this->get(route('crm.deals.show', $deal))->assertStatus(in_array('viewAny', $allowed, true) ? 200 : 403);
})->with('crm deal roles');

it('shows the board per stage with counts, sums and weighted sums, and the cards\' next step', function () {
    Crm::deal($this->company, $this->owner, $this->account, 'Ramavtal', 1_000_000, ['expected_close_date' => '2026-06-30']);
    $late = Crm::deal($this->company, $this->owner, $this->account, 'Sen affär', 200_000, ['stage_id' => Crm::stage(2)->id]);
    Activity::query()->where('deal_id', $late->id)->update(['done_at' => now()]);

    expect(route('crm.deals', [], false))->toBe('/kunder/affarer')
        ->and(route('crm.deals.show', $late, false))->toBe("/kunder/affarer/{$late->id}");

    $this->get(route('crm.deals'))->assertOk()
        ->assertSeeInOrder(['Ny kontakt', '1 affär', 'Ramavtal', 'Prospektet AB', 'Nästa steg 16 jun', 'Offert', 'Sen affär', 'Nästa steg saknas'])
        ->assertSee('viktat');

    Livewire::test(DealsBoard::class)->set('show', 'utan-steg')->assertSee('Sen affär')->assertDontSee('Ramavtal');
});

it('filters the board by owner and pipeline from the query string', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    Crm::deal($this->company, $this->owner, $this->account, 'Ägarens affär');
    Crm::deal($this->company, $this->owner, $this->account, 'Säljarens affär', extra: ['owner_id' => $seller->id]);

    $this->get(route('crm.deals', ['ansvarig' => $seller->id]))->assertOk()->assertSee('Säljarens affär')->assertDontSee('Ägarens affär');
    $this->actingAs($seller)->get(route('crm.deals', ['ansvarig' => 'mina']))->assertOk()->assertSee('Säljarens affär')->assertDontSee('Ägarens affär');
});

it('creates a deal from the board with its next step, and keeps the form open with errors without one', function () {
    $page = Livewire::test(DealsBoard::class)
        ->call('startCreate')
        ->set('accountSearch', 'Prospekt')
        ->assertSee('Prospektet AB')
        ->call('pickAccount', $this->account->id)
        ->set('form.title', 'Ny affär från tavlan')
        ->set('form.value', '45 000')
        ->set('form.next_type', '')
        ->call('create')
        ->assertHasErrors(['next_step.type']);

    expect(Deal::query()->count())->toBe(0);

    $page->set('form.next_type', 'MEETING')->call('create')->assertHasNoErrors()->assertDispatched('toast');

    $deal = Deal::query()->sole();
    expect($deal->value_ore)->toBe(4_500_000)
        ->and(Activity::query()->where('deal_id', $deal->id)->whereNull('done_at')->sole()->type->value)->toBe('MEETING');
});

it('opens the new-deal form for an account from its card, and lists the account\'s deals there', function () {
    Crm::deal($this->company, $this->owner, $this->account, 'Kortets affär');

    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])
        ->assertSee('Kortets affär')
        ->assertSee(route('crm.deals', ['ny' => $this->account->id]), escape: false);

    Livewire::withQueryParams(['ny' => (string) $this->account->id])->test(DealsBoard::class)
        ->assertSet('creating', true)
        ->assertSet('form.account_id', (string) $this->account->id);
});

it('moves a card to another stage, and refuses a stage that does not exist', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account);

    Livewire::test(DealsBoard::class)->call('move', $deal->id, Crm::stage(3)->id)->assertDispatched('toast');
    expect($deal->fresh()?->stage_id)->toBe(Crm::stage(3)->id);

    Livewire::test(DealsBoard::class)->call('move', $deal->id, 999_999)->assertDispatched('toast');
    expect($deal->fresh()?->stage_id)->toBe(Crm::stage(3)->id);
});

it('keeps the board at a fixed number of queries for 2 and 30 deals', function () {
    foreach (range(1, 2) as $i) {
        Crm::deal($this->company, $this->owner, $this->account, "Affär {$i}");
    }
    $few = queriesFor(DealsBoard::class);

    foreach (range(3, 30) as $i) {
        Crm::deal($this->company, $this->owner, Crm::prospect($this->company, $this->owner, "Prospekt {$i}"), "Affär {$i}", extra: ['stage_id' => Crm::stage($i % 4)->id, 'owner_id' => Bookkeeping::member($this->company, 'sales')->id]);
    }

    expect(queriesFor(DealsBoard::class))->toBe($few);
});

it('keeps the dashboard and the forecast at a fixed number of queries', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    $goal = fn ($user) => app(SaveGoal::class)($this->owner, $this->company, ['user_id' => $user->id, 'month' => '2026-06', 'metric' => 'ORDER_VALUE', 'target' => '10 000']);
    $goal($this->owner);
    Crm::deal($this->company, $this->owner, $this->account);
    $dashboard = queriesFor(Dashboard::class);
    $forecast = queriesFor(ForecastPage::class);

    foreach (range(1, 15) as $i) {
        $member = Bookkeeping::member($this->company, 'sales');
        $goal($member);
        $deal = Crm::deal($this->company, $this->owner, $this->account, "Affär {$i}", extra: ['owner_id' => $member->id, 'expected_close_date' => '2026-0'.(6 + $i % 3).'-15']);
        $i % 2 === 0 ? app(WinDeal::class)($this->owner, $this->company, $deal) : null;
        // A fresh company: Jetstream caches the members on the model it is given.
        app(LogActivity::class)($member, $this->company->fresh(), ['account_id' => $this->account->id, 'type' => 'MEETING', 'done' => true]);
    }

    expect(queriesFor(Dashboard::class))->toBe($dashboard)
        ->and(queriesFor(ForecastPage::class))->toBe($forecast)
        ->and($seller->id)->toBeInt();
});

it('shows the dashboard\'s figures, everyone\'s or the user\'s own', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account, 'Vunnen', 600_000);
    app(WinDeal::class)($this->owner, $this->company, $deal);
    Crm::deal($this->company, $this->owner, $this->account, 'Öppen', 1_000_000);

    expect(route('crm.dashboard', [], false))->toBe('/kunder/oversikt');

    $this->get(route('crm.dashboard'))->assertOk()
        ->assertSee('Öppna affärer (1)')
        ->assertSee('100 %')
        ->assertSee('Aktiviteter per vecka')
        ->assertSee('Mål den här månaden');

    $seller = Bookkeeping::member($this->company, 'sales');
    $this->actingAs($seller);
    Livewire::test(Dashboard::class)->set('who', 'mina')->assertSee('Öppna affärer (0)')->assertSee('Du har inga mål för månaden.');
});

it('shows the forecast and lets crm:settings set goals, a seller only their own', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    Crm::deal($this->company, $this->owner, $this->account, 'Juli', 1_000_000, ['expected_close_date' => '2026-07-10', 'stage_id' => Crm::stage(2)->id]);

    expect(route('crm.forecast', [], false))->toBe('/kunder/prognos');
    $this->get(route('crm.forecast'))->assertOk()->assertSeeInOrder(['Juli 2026', Money::ofOre(500_000)->format(), Money::ofOre(1_000_000)->format()]);

    Livewire::test(ForecastPage::class)
        ->set('goal.user_id', (string) $seller->id)
        ->set('goal.metric', 'MEETINGS')
        ->set('goal.target', '6')
        ->call('saveGoal')
        ->assertHasNoErrors()
        ->assertSee('0 av 6');

    $this->actingAs($seller);
    Livewire::test(ForecastPage::class)->assertSee('0 av 6')->assertDontSee('Spara mål');

    $this->actingAs($this->owner);
    Livewire::test(ForecastPage::class)->call('removeGoal', Goal::query()->sole()->id)->assertDontSee('0 av 6');
});

it('completes the last step on the deal\'s card only with the next one, and wins and loses the deal there', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account);
    $step = Activity::query()->where('deal_id', $deal->id)->sole();

    $card = Livewire::test(DealCard::class, ['dealId' => $deal->id])
        ->call('startCompletion', $step->id)
        ->assertSet('nextRequired', true)
        ->assertSee('Det här är affärens enda planerade steg')
        ->set('step.due_at', 'inte ett datum')
        ->call('complete')
        ->assertHasErrors();

    expect($step->fresh()?->isOpen())->toBeTrue();

    $card->set('step.due_at', '2026-06-18T10:00')->set('step.subject', 'Skicka offert')->call('complete')->assertHasNoErrors();
    expect($step->fresh()?->isOpen())->toBeFalse()
        ->and(Activity::query()->where('deal_id', $deal->id)->whereNull('done_at')->sole()->subject)->toBe('Skicka offert');

    $card->call('startLose')->set('lose.loss_reason_id', '')->call('saveLose')->assertHasErrors(['loss_reason_id'])
        ->set('lose.loss_reason_id', (string) LossReason::query()->value('id'))->set('lose.win_back_at', '2026-11-01')->call('saveLose')->assertHasNoErrors()
        ->assertSee('Vinn tillbaka: 1 november 2026');

    expect($deal->fresh()?->status->value)->toBe('LOST');

    $won = Crm::deal($this->company, $this->owner, $this->account, 'Vinnaren', 100_000);
    Livewire::test(DealCard::class, ['dealId' => $won->id])->call('startWin')->set('win.value', '150 000')->call('saveWin')->assertHasNoErrors();
    expect($won->fresh()?->value_ore)->toBe(15_000_000);
});

it('asks for the next step without a skip when the deal\'s last step is ticked off in Mina aktiviteter', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account);
    $step = Activity::query()->where('deal_id', $deal->id)->sole();

    $page = Livewire::test(ActivitiesPage::class)
        ->call('done', $step->id)
        ->assertSet('pendingActivityId', $step->id)
        ->assertSee('Avsluta affären')
        ->assertDontSee('Hoppa över');

    expect($step->fresh()?->isOpen())->toBeTrue();

    $page->set('next.subject', 'Boka möte')->call('saveNext')->assertHasNoErrors();
    expect($step->fresh()?->isOpen())->toBeFalse()
        ->and(Activity::query()->where('deal_id', $deal->id)->whereNull('done_at')->sole()->subject)->toBe('Boka möte');
});

it('asks for the next step without a skip when the deal\'s last step is completed on the account card', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account);
    $step = Activity::query()->where('deal_id', $deal->id)->sole();

    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])
        ->call('startCompletion', $step->id)
        ->assertSet('dealStepRequired', true)
        ->assertSee('Nästa steg i affären')
        ->set('activity.subject', 'Ring igen')
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSet('panel', '');

    expect(Activity::query()->where('deal_id', $deal->id)->whereNull('done_at')->sole()->subject)->toBe('Ring igen');
});
