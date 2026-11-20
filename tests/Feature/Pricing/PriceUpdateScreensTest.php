<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\ApplyPriceUpdate;
use App\Actions\Pricing\PlanPriceUpdate;
use App\Domain\Pricing\PriceUpdateStatus;
use App\Livewire\Pricing\PriceListItems;
use App\Livewire\Pricing\PriceUpdatePreview;
use App\Livewire\Pricing\PriceUpdates;
use App\Models\Article;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * ADR-082 P6: the price update screens. Thin Livewire over the actions, a
 * fixed number of queries for a preview of one price or a thousand, and
 * another company's update is a 404.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->article = app(SaveArticle::class)($this->owner, $this->company, ['number' => '1001', 'name' => 'Skruv', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    $this->list = PriceList::create(['code' => 'ATER', 'name' => 'Återförsäljare', 'created_by' => $this->owner->id]);
    PriceListItem::create(['price_list_id' => $this->list->id, 'article_id' => $this->article->id, 'price_minor' => 9_000, 'valid_from' => '2026-01-01']);
});

it('shows the pages to someone who can see prices, the form only to someone who can change them', function () {
    $this->get(route('pricing.updates'))->assertOk()->assertSee('Ny prisuppdatering')->assertSee('Förhandsgranska');
    $this->get(route('pricing.lists'))->assertOk()->assertSee('Prisuppdatering');

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->actingAs($bookkeeper)->get(route('pricing.updates'))->assertOk()->assertDontSee('Ny prisuppdatering');

    $approver = Bookkeeping::member($this->company, 'approver');
    $this->actingAs($approver)->get(route('pricing.updates'))->assertForbidden();
});

it('plans from the form, applies and reverts from the preview with Swedish toasts', function () {
    Livewire::test(PriceUpdates::class)
        ->set('form.percent', '10')
        ->set('form.list_ids', [(string) $this->list->id])
        ->set('form.rounding', 'WHOLE')
        ->call('plan')
        ->assertHasNoErrors()
        ->assertRedirect(route('pricing.updates.show', PriceUpdateRun::query()->latest('id')->firstOrFail()->id));

    $run = PriceUpdateRun::query()->latest('id')->firstOrFail();

    $this->get(route('pricing.updates.show', $run->id))->assertOk()->assertSee('1001')->assertSee('Genomför');

    Livewire::test(PriceUpdatePreview::class, ['runId' => $run->id])
        ->assertSee('99,00')
        ->call('apply')
        ->assertDispatched('toast', message: '2 priser är ändrade.', type: 'success')
        ->call('revert')
        ->assertDispatched('toast', type: 'success');

    expect($run->refresh()->status)->toBe(PriceUpdateStatus::Reverted)
        ->and(Article::query()->find($this->article->id)->price_ore->ore)->toBe(10_000);
});

it('shows validation errors from the action on the form', function () {
    Livewire::test(PriceUpdates::class)
        ->set('form.percent', '')
        ->call('plan')
        ->assertHasErrors(['percent']);

    Livewire::test(PriceUpdates::class)
        ->set('form.kind', 'FILE')
        ->call('plan')
        ->assertHasErrors(['file']);
});

it('downloads the list and the diff as files', function () {
    Livewire::test(PriceListItems::class, ['listId' => $this->list->id])
        ->call('export', 'csv')
        ->assertFileDownloaded('prislista-ater.csv');

    $run = app(PlanPriceUpdate::class)($this->owner, $this->company, ['kind' => 'PERCENT', 'percent' => '4', 'base' => true, 'effective_date' => '2026-06-15']);

    Livewire::test(PriceUpdatePreview::class, ['runId' => $run->id])
        ->call('downloadDiff')
        ->assertFileDownloaded("prisuppdatering-{$run->id}.csv");
});

it('answers 404 for another company\'s price update', function () {
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $foreign = CurrentCompany::run($otherCompany, function () use ($otherOwner, $otherCompany): PriceUpdateRun {
        app(SaveArticle::class)($otherOwner, $otherCompany, ['number' => 'H-1', 'name' => 'Hemlig', 'unit' => 'st', 'price' => '5', 'vat_rate' => 2500]);

        return app(PlanPriceUpdate::class)($otherOwner, $otherCompany, ['kind' => 'PERCENT', 'percent' => '4', 'base' => true, 'effective_date' => '2026-06-15']);
    });
    CurrentCompany::set($this->company);

    $this->get(route('pricing.updates.show', $foreign->id))->assertNotFound();

    expect(PriceUpdateRun::query()->whereKey($foreign->id)->exists())->toBeFalse()
        ->and(PriceUpdateRunLine::query()->where('price_update_run_id', $foreign->id)->exists())->toBeFalse()
        ->and(fn () => app(ApplyPriceUpdate::class)($this->owner, $this->company, $foreign))->toThrow(ModelNotFoundException::class);
});

it('plans and previews one price or a thousand with the same number of queries', function () {
    $more = [];

    for ($i = 2; $i <= 1000; $i++) {
        $copy = $this->article->replicate();
        $copy->number = (string) (1000 + $i);
        $copy->save();
        $more[] = ['company_id' => $this->company->id, 'price_list_id' => $this->list->id, 'article_id' => $copy->id, 'price_minor' => 9_000, 'min_quantity_milli' => 0, 'valid_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()];
    }

    PriceListItem::query()->insert($more);

    $plan = fn (array $articles) => app(PlanPriceUpdate::class)($this->owner, $this->company, [
        'kind' => 'PERCENT', 'percent' => '4', 'base' => false, 'list_ids' => [$this->list->id], 'effective_date' => '2026-06-15', ...$articles,
    ]);

    $count = function (callable $callback): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $callback();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $result];
    };

    [$planOne, $one] = $count(fn () => $plan(['articles' => 'selected', 'article_ids' => [$this->article->id]]));
    [$planMany, $many] = $count(fn () => $plan(['articles' => 'all']));

    expect($one->counts['lines'])->toBe(1)
        ->and($many->counts['lines'])->toBe(1000)
        ->and($planMany)->toBe($planOne);

    // Once first, so the signed-in user's company and roles are loaded before counting.
    Livewire::test(PriceUpdatePreview::class, ['runId' => $one->id]);

    [$previewOne] = $count(fn () => Livewire::test(PriceUpdatePreview::class, ['runId' => $one->id]));
    [$previewMany] = $count(fn () => Livewire::test(PriceUpdatePreview::class, ['runId' => $many->id]));

    expect($previewMany)->toBe($previewOne);
});
