<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\ApplyPriceUpdate;
use App\Actions\Pricing\PlanPriceUpdate;
use App\Domain\Pricing\DocumentPricing;
use App\Domain\Pricing\PriceRounding;
use App\Domain\Pricing\PriceUpdateRefusal;
use App\Domain\Pricing\PriceUpdateRule;
use App\Domain\Pricing\RowMargin;
use App\Livewire\Pricing\PriceUpdatePreview;
use App\Livewire\Pricing\PriceUpdates;
use App\Models\Article;
use App\Models\CustomerPriceGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceListPriceGroup;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * ADR-082 §8, P6 follow-up: a mass update that sets the price from a target
 * contribution margin (the definition of RowMargin and the margin floor, §9),
 * and filters by supplier and customer group.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    $this->supplierA = Supplier::create(['number' => 1, 'name' => 'Leverantör A', 'payment_terms_days' => 30]);
    $this->supplierB = Supplier::create(['number' => 2, 'name' => 'Leverantör B', 'payment_terms_days' => 30]);

    $save = app(SaveArticle::class);
    // Cost 70 kr: a price of 100 kr is a margin of 30 %.
    $this->bolt = $save($this->owner, $this->company, ['number' => '1001', 'name' => 'Bult', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500, 'purchase_price' => '70']);
    $this->nut = $save($this->owner, $this->company, ['number' => '1002', 'name' => 'Mutter', 'unit' => 'st', 'price' => '10', 'vat_rate' => 2500, 'purchase_price' => '3']);
    $this->free = $save($this->owner, $this->company, ['number' => '1003', 'name' => 'Utan inköpspris', 'unit' => 'st', 'price' => '50', 'vat_rate' => 2500]);
    $this->zero = $save($this->owner, $this->company, ['number' => '1004', 'name' => 'Gratis inköp', 'unit' => 'st', 'price' => '50', 'vat_rate' => 2500]);
    Article::query()->whereKey($this->zero->id)->update(['purchase_price_ore' => 0]);
    Article::query()->whereKey([$this->bolt->id])->update(['default_supplier_id' => $this->supplierA->id]);
    Article::query()->whereKey([$this->nut->id, $this->free->id])->update(['default_supplier_id' => $this->supplierB->id]);

    $this->plan = fn (array $input): PriceUpdateRun => app(PlanPriceUpdate::class)($this->owner, $this->company, [
        'kind' => 'MARGIN', 'margin' => '30', 'base' => true, 'list_ids' => [], 'articles' => 'all', 'rounding' => 'NONE', 'effective_date' => '2026-06-15', ...$input,
    ]);
});

function marginLine(PriceUpdateRun $run, int $articleId, string $target = 'BASE'): PriceUpdateRunLine
{
    return PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->where('article_id', $articleId)->where('target', $target)->firstOrFail();
}

it('works out the price that gives the target margin over cost', function () {
    expect(PriceUpdateRule::margin(3000)->priceFromCost(7_000))->toBe(10_000)
        ->and(PriceUpdateRule::margin(5000)->priceFromCost(7_000))->toBe(14_000)
        ->and(PriceUpdateRule::margin(0)->priceFromCost(7_000))->toBe(7_000)
        // 333 / 0,7 = 475,714... rounds half up to 476.
        ->and(PriceUpdateRule::margin(3000)->priceFromCost(333))->toBe(476)
        // Net 100,00 + 25 % VAT.
        ->and(PriceUpdateRule::margin(3000)->priceFromCost(7_000, 2500))->toBe(12_500)
        ->and(fn () => PriceUpdateRule::margin(10_000))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PriceUpdateRule::margin(-1))->toThrow(InvalidArgumentException::class);
});

it('previews base prices from the margin and writes nothing', function () {
    $run = ($this->plan)([]);

    expect($run->kind->value)->toBe('MARGIN')
        ->and($run->rule)->toBe(['margin_bp' => 3000])
        ->and(marginLine($run, $this->bolt->id)->new_minor)->toBe(10_000)
        ->and(marginLine($run, $this->bolt->id)->status->value)->toBe('UNCHANGED')
        // 300 / 0,7 = 428,57 -> 429 öre.
        ->and(marginLine($run, $this->nut->id)->new_minor)->toBe(429)
        ->and(marginLine($run, $this->nut->id)->cost_minor)->toBe(300)
        ->and(Article::query()->find($this->nut->id)->price_ore->ore)->toBe(1_000);
});

it('skips items without a cost and lists them with the reason', function () {
    $run = ($this->plan)([]);

    expect(marginLine($run, $this->free->id)->reason)->toBe(PriceUpdateRefusal::MissingCost)
        ->and(marginLine($run, $this->free->id)->new_minor)->toBeNull()
        ->and(marginLine($run, $this->zero->id)->reason)->toBe(PriceUpdateRefusal::MissingCost)
        ->and(PriceUpdateRefusal::MissingCost->label())->toContain('saknar inköpspris')
        ->and($run->counts['refused'])->toBe(2);
});

it('rounds as the update\'s rounding rule says', function () {
    $run = ($this->plan)(['rounding' => 'WHOLE']);

    expect(marginLine($run, $this->nut->id)->unrounded_minor)->toBe(429)
        ->and(marginLine($run, $this->nut->id)->new_minor)->toBe(400)
        ->and(marginLine($run, $this->nut->id)->flags)->toContain('rounding_moved');

    $nine = ($this->plan)(['rounding' => 'ENDS_IN_9', 'margin' => '40']);
    expect(marginLine($nine, $this->bolt->id)->unrounded_minor)->toBe(11_667)
        ->and(marginLine($nine, $this->bolt->id)->new_minor)->toBe(PriceRounding::EndsIn9->apply(11_667));
});

it('refuses a margin of 100 % or more, a negative one and an unreadable one', function () {
    foreach (['100', '120', '-5', 'abc', ''] as $bad) {
        expect(fn () => ($this->plan)(['margin' => $bad]))->toThrow(ValidationException::class);
    }

    expect(($this->plan)(['margin' => '99,99'])->rule)->toBe(['margin_bp' => 9999]);
});

it('works out a list including VAT on the net price and derives the gross', function () {
    $gross = PriceList::create(['code' => 'INKL', 'name' => 'Inklusive moms', 'prices_include_vat' => true, 'created_by' => $this->owner->id]);
    PriceListItem::create(['price_list_id' => $gross->id, 'article_id' => $this->bolt->id, 'price_minor' => 9_000, 'valid_from' => '2026-01-01']);
    $net = PriceList::create(['code' => 'EXKL', 'name' => 'Exklusive moms', 'created_by' => $this->owner->id]);
    PriceListItem::create(['price_list_id' => $net->id, 'article_id' => $this->bolt->id, 'price_minor' => 9_000, 'valid_from' => '2026-01-01']);

    $run = ($this->plan)(['base' => false, 'list_ids' => [$gross->id, $net->id]]);

    $grossLine = PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->where('price_list_id', $gross->id)->firstOrFail();
    $netLine = PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->where('price_list_id', $net->id)->firstOrFail();

    // Cost 70,00 at 30 % is a net price of 100,00; with 25 % VAT the gross is 125,00.
    expect($grossLine->new_minor)->toBe(12_500)
        ->and($grossLine->cost_minor)->toBeNull()
        ->and($netLine->new_minor)->toBe(10_000)
        ->and($netLine->cost_minor)->toBe(7_000);
});

it('refuses a list in another currency and a price that is a discount', function () {
    $eur = PriceList::create(['code' => 'EUR', 'name' => 'Euro', 'currency' => 'EUR', 'created_by' => $this->owner->id]);
    PriceListItem::create(['price_list_id' => $eur->id, 'article_id' => $this->bolt->id, 'price_minor' => 900, 'valid_from' => '2026-01-01']);
    PriceListItem::create(['price_list_id' => $eur->id, 'article_id' => $this->nut->id, 'discount_bp' => 1000, 'valid_from' => '2026-01-01']);

    $run = ($this->plan)(['base' => false, 'list_ids' => [$eur->id]]);

    expect(marginLine($run, $this->bolt->id, 'LIST')->reason)->toBe(PriceUpdateRefusal::MarginOtherCurrency)
        ->and(marginLine($run, $this->nut->id, 'LIST')->reason)->toBe(PriceUpdateRefusal::DiscountItem);
});

it('applies a margin run like any other', function () {
    $run = ($this->plan)(['margin' => '50']);
    $applied = app(ApplyPriceUpdate::class)($this->owner, $this->company, $run);

    expect($applied->status->value)->toBe('APPLIED')
        ->and(Article::query()->find($this->bolt->id)->price_ore->ore)->toBe(14_000)
        ->and(Article::query()->find($this->free->id)->price_ore->ore)->toBe(5_000);
});

it('filters by supplier, together with the other article filters', function () {
    $run = ($this->plan)(['supplier_ids' => [$this->supplierA->id]]);

    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->pluck('article_id')->all())->toBe([$this->bolt->id])
        ->and($run->scope['supplier_ids'])->toBe([$this->supplierA->id]);

    $both = ($this->plan)(['supplier_ids' => [$this->supplierB->id], 'articles' => 'range', 'number_from' => '1002', 'number_to' => '1002']);
    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $both->id)->pluck('article_id')->all())->toBe([$this->nut->id]);

    $percent = ($this->plan)(['kind' => 'PERCENT', 'percent' => '10', 'supplier_ids' => [$this->supplierB->id]]);
    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $percent->id)->count())->toBe(2);

    $none = ($this->plan)(['supplier_ids' => [$this->supplierA->id], 'articles' => 'search', 'search' => 'Bult']);
    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $none->id)->count())->toBe(1);

    expect(fn () => ($this->plan)(['supplier_ids' => [$this->supplierA->id], 'articles' => 'search', 'search' => 'Mutter']))
        ->toThrow(ValidationException::class);
});

it('refuses another company\'s supplier and an unknown one', function () {
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $foreign = CurrentCompany::run($otherCompany, fn (): Supplier => Supplier::create(['number' => 1, 'name' => 'Hemlig', 'payment_terms_days' => 30]));
    CurrentCompany::set($this->company);

    expect(fn () => ($this->plan)(['supplier_ids' => [$foreign->id]]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->plan)(['supplier_ids' => [999_999]]))->toThrow(ValidationException::class)
        ->and(PriceUpdateRun::query()->count())->toBe(0);
});

it('limits a customer-group update to the group\'s list and its campaigns', function () {
    $resellers = PriceList::create(['code' => 'ATER', 'name' => 'Återförsäljare', 'created_by' => $this->owner->id]);
    $campaign = PriceList::create(['code' => 'KAMP', 'name' => 'Kampanj', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'created_by' => $this->owner->id]);
    $other = PriceList::create(['code' => 'ANNAN', 'name' => 'Annan', 'created_by' => $this->owner->id]);

    foreach ([$resellers, $campaign, $other] as $list) {
        PriceListItem::create(['price_list_id' => $list->id, 'article_id' => $this->bolt->id, 'price_minor' => 9_000, 'valid_from' => '2026-01-01']);
    }

    $group = CustomerPriceGroup::create(['code' => 'ATER', 'name' => 'Återförsäljare', 'price_list_id' => $resellers->id]);
    PriceListPriceGroup::create(['price_list_id' => $campaign->id, 'customer_price_group_id' => $group->id]);

    $run = ($this->plan)(['base' => false, 'price_group_ids' => [$group->id]]);
    $lists = PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->pluck('price_list_id')->unique()->sort()->values()->all();

    expect($lists)->toBe(collect([$resellers->id, $campaign->id])->sort()->values()->all())
        ->and($run->scope['price_group_ids'])->toBe([$group->id]);

    $chosen = ($this->plan)(['base' => false, 'price_group_ids' => [$group->id], 'list_ids' => [$resellers->id]]);
    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $chosen->id)->pluck('price_list_id')->unique()->all())->toBe([$resellers->id]);

    expect(fn () => ($this->plan)(['base' => false, 'price_group_ids' => [$group->id], 'list_ids' => [$other->id]]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->plan)(['base' => true, 'price_group_ids' => [$group->id]]))->toThrow(ValidationException::class);
});

it('refuses another company\'s customer group, an archived one and a group without a list', function () {
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $foreign = CurrentCompany::run($otherCompany, fn (): CustomerPriceGroup => CustomerPriceGroup::create(['code' => 'HEMLIG', 'name' => 'Hemlig']));
    CurrentCompany::set($this->company);
    $empty = CustomerPriceGroup::create(['code' => 'TOM', 'name' => 'Utan lista']);
    $archived = CustomerPriceGroup::create(['code' => 'GAMMAL', 'name' => 'Gammal', 'archived_at' => now()]);

    foreach ([$foreign->id, $archived->id, $empty->id, 999_999] as $id) {
        expect(fn () => ($this->plan)(['base' => false, 'price_group_ids' => [$id]]))->toThrow(ValidationException::class);
    }

    expect(PriceUpdateRun::query()->count())->toBe(0);
});

it('plans from a margin with a fixed number of queries whatever the number of articles', function () {
    $count = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        ($this->plan)(['rounding' => 'WHOLE', 'list_ids' => $this->listIds ?? []]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $list = PriceList::create(['code' => 'QC', 'name' => 'Frågor', 'created_by' => $this->owner->id]);
    PriceListItem::create(['price_list_id' => $list->id, 'article_id' => $this->bolt->id, 'price_minor' => 9_000, 'valid_from' => '2026-01-01']);
    $this->listIds = [$list->id];

    $few = $count();
    $rows = [];

    for ($i = 0; $i < 300; $i++) {
        $rows[] = ['company_id' => $this->company->id, 'number' => (string) (5000 + $i), 'name' => "Artikel {$i}", 'unit' => 'st', 'price_ore' => 1_000, 'purchase_price_ore' => 400, 'vat_rate' => 2500, 'is_active' => true, 'account_id' => $this->bolt->account_id, 'default_supplier_id' => $this->supplierA->id, 'created_at' => now(), 'updated_at' => now()];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('articles')->insert($chunk);
    }

    $items = [];

    foreach (DB::table('articles')->where('number', '>=', '5000')->pluck('id') as $id) {
        $items[] = ['company_id' => $this->company->id, 'price_list_id' => $this->listIds[0], 'article_id' => $id, 'price_minor' => 900, 'min_quantity_milli' => 0, 'valid_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()];
    }

    foreach (array_chunk($items, 100) as $chunk) {
        DB::table('price_list_items')->insert($chunk);
    }

    expect($count())->toBe($few);
});

it('needs prices:view-cost as well as price-lists:manage for a margin', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => app(PlanPriceUpdate::class)($bookkeeper, $this->company, ['kind' => 'MARGIN', 'margin' => '30', 'base' => true, 'effective_date' => '2026-06-15']))
        ->toThrow(AuthorizationException::class);

    $auditor = Bookkeeping::member($this->company, 'auditor');
    expect(fn () => app(PlanPriceUpdate::class)($auditor, $this->company->fresh(), ['kind' => 'MARGIN', 'margin' => '30', 'base' => true, 'effective_date' => '2026-06-15']))
        ->toThrow(AuthorizationException::class);
});

it('offers the margin and the filters on the form and plans from them', function () {
    $this->actingAs($this->owner);

    Livewire::test(PriceUpdates::class)
        ->assertSee('Mål för täckningsgrad')
        ->assertSee('Leverantör A')
        ->set('form.kind', 'MARGIN')
        ->assertSee('Önskad täckningsgrad i procent')
        ->set('form.margin', '50')
        ->set('form.supplier_id', (string) $this->supplierA->id)
        ->call('plan')
        ->assertHasNoErrors();

    $run = PriceUpdateRun::query()->latest('id')->firstOrFail();
    expect($run->rule)->toBe(['margin_bp' => 5000])
        ->and($run->scope['supplier_ids'])->toBe([$this->supplierA->id])
        ->and(PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->count())->toBe(1);

    Livewire::test(PriceUpdates::class)->set('form.kind', 'MARGIN')->set('form.margin', '100')->call('plan')->assertHasErrors(['margin']);
});

it('reaches the target margin for a cost that does not divide evenly, on net and gross lists', function () {
    $odd = app(SaveArticle::class)($this->owner, $this->company, ['number' => '1010', 'name' => 'Udda', 'unit' => 'st', 'price' => '50', 'vat_rate' => 2500, 'purchase_price' => '70,01']);
    $gross = PriceList::create(['code' => 'G', 'name' => 'Brutto', 'prices_include_vat' => true, 'created_by' => $this->owner->id]);
    $net = PriceList::create(['code' => 'N', 'name' => 'Netto', 'created_by' => $this->owner->id]);

    foreach ([$gross, $net] as $list) {
        PriceListItem::create(['price_list_id' => $list->id, 'article_id' => $odd->id, 'price_minor' => 5_000, 'valid_from' => '2026-01-01']);
    }

    expect(PriceUpdateRule::margin(3000)->priceFromCost(7001))->toBe(10_002);

    $run = ($this->plan)(['base' => true, 'list_ids' => [$gross->id, $net->id], 'articles' => 'range', 'number_from' => '1010', 'number_to' => '1010']);
    $base = marginLine($run, $odd->id);
    $netLine = PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->where('price_list_id', $net->id)->firstOrFail();
    $grossLine = PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->where('price_list_id', $gross->id)->firstOrFail();

    expect($netLine->new_minor)->toBe(10_002)
        ->and($base->new_minor)->toBe(10_002)
        ->and(RowMargin::of($netLine->new_minor, 7001, 3000)->belowFloor)->toBeFalse()
        ->and($grossLine->new_minor)->toBe(12_503)
        ->and(RowMargin::of(DocumentPricing::consumerNet($grossLine->new_minor, 2500), 7001, 3000)->belowFloor)->toBeFalse()
        ->and($netLine->flags ?? [])->not->toContain('below_target:3000', 'below_floor')
        ->and($grossLine->flags ?? [])->each->not->toStartWith('below_');
});

it('flags a line that the rounding rule takes below the target margin or the floor, without changing the price', function () {
    $odd = app(SaveArticle::class)($this->owner, $this->company, ['number' => '1010', 'name' => 'Udda', 'unit' => 'st', 'price' => '50', 'vat_rate' => 2500, 'purchase_price' => '70,01']);
    $this->company->forceFill(['min_contribution_bp' => 3000])->save();

    $run = ($this->plan)(['rounding' => 'WHOLE', 'articles' => 'range', 'number_from' => '1010', 'number_to' => '1010']);
    $line = marginLine($run, $odd->id);

    // 100,02 rounds down to 100,00: (10000 - 7001) / 10000 = 29,99 %.
    expect($line->new_minor)->toBe(10_000)
        ->and($line->flags)->toContain('below_target:2999', 'below_floor');

    $this->actingAs($this->owner);
    Livewire::test(PriceUpdatePreview::class, ['runId' => $run->id])->assertSee('Under målet: 29,99 % täckningsgrad')->assertSee('Under lägsta täckningsgrad');

    $up = ($this->plan)(['rounding' => 'NONE', 'articles' => 'range', 'number_from' => '1010', 'number_to' => '1010']);
    expect(marginLine($up, $odd->id)->flags ?? [])->not->toContain('below_target:2999', 'below_floor');
});

it('prices from the exact cost per unit, not a cost rounded per unit first', function () {
    $kg = app(SaveArticle::class)($this->owner, $this->company, ['number' => '1020', 'name' => 'Mjöl', 'unit' => 'kg', 'price' => '200', 'vat_rate' => 1200, 'purchase_price' => '123,45']);
    $gram = Unit::query()->where('code', 'g')->firstOrFail();
    $list = PriceList::create(['code' => 'GRAM', 'name' => 'Per gram', 'created_by' => $this->owner->id]);
    PriceListItem::create(['price_list_id' => $list->id, 'article_id' => $kg->id, 'unit_id' => $gram->id, 'price_minor' => 20, 'valid_from' => '2026-01-01']);

    $run = ($this->plan)(['base' => false, 'list_ids' => [$list->id], 'articles' => 'range', 'number_from' => '1020', 'number_to' => '1020']);
    $line = marginLine($run, $kg->id, 'LIST');

    // 12 345 öre / 1 000 g / 0,7 = 17,64 öre, rounded up once: 18 öre per gram.
    expect($line->new_minor)->toBe(18)
        ->and(RowMargin::of(18 * 1000, 12_345, 3000)->belowFloor)->toBeFalse();
});

it('refuses a unit that does not convert, and a cost too small to price, with their own reasons', function () {
    $kg = app(SaveArticle::class)($this->owner, $this->company, ['number' => '1020', 'name' => 'Mjöl', 'unit' => 'kg', 'price' => '200', 'vat_rate' => 1200, 'purchase_price' => '123,45']);
    $tiny = app(SaveArticle::class)($this->owner, $this->company, ['number' => '1021', 'name' => 'Sand', 'unit' => 'kg', 'price' => '2', 'vat_rate' => 1200, 'purchase_price' => '0,01']);
    $metre = Unit::query()->where('code', 'm')->firstOrFail();
    $gram = Unit::query()->where('code', 'g')->firstOrFail();
    $list = PriceList::create(['code' => 'OMV', 'name' => 'Omvandling', 'created_by' => $this->owner->id]);
    PriceListItem::create(['price_list_id' => $list->id, 'article_id' => $kg->id, 'unit_id' => $metre->id, 'price_minor' => 20, 'valid_from' => '2026-01-01']);
    PriceListItem::create(['price_list_id' => $list->id, 'article_id' => $tiny->id, 'unit_id' => $gram->id, 'price_minor' => 1, 'valid_from' => '2026-01-01']);

    $run = ($this->plan)(['base' => false, 'list_ids' => [$list->id], 'articles' => 'range', 'number_from' => '1020', 'number_to' => '1021']);

    expect(marginLine($run, $kg->id, 'LIST')->reason)->toBe(PriceUpdateRefusal::UnitNotConvertible)
        ->and(marginLine($run, $tiny->id, 'LIST')->reason)->toBe(PriceUpdateRefusal::CostTooSmall)
        ->and(PriceUpdateRefusal::CostTooSmall->label())->not->toContain('saknar inköpspris');
});

it('leaves archived lists and campaigns of a customer group out when no list is chosen', function () {
    $own = PriceList::create(['code' => 'GR', 'name' => 'Gruppens', 'created_by' => $this->owner->id]);
    $oldCampaign = PriceList::create(['code' => 'GAMMAL', 'name' => 'Gammal kampanj', 'kind' => 'CAMPAIGN', 'valid_from' => '2025-01-01', 'valid_to' => '2025-12-31', 'archived_at' => now(), 'created_by' => $this->owner->id]);

    foreach ([$own, $oldCampaign] as $list) {
        PriceListItem::create(['price_list_id' => $list->id, 'article_id' => $this->bolt->id, 'price_minor' => 9_000, 'valid_from' => '2026-01-01']);
    }

    $group = CustomerPriceGroup::create(['code' => 'GR', 'name' => 'Grupp', 'price_list_id' => $own->id]);
    PriceListPriceGroup::create(['price_list_id' => $oldCampaign->id, 'customer_price_group_id' => $group->id]);

    $run = ($this->plan)(['base' => false, 'price_group_ids' => [$group->id]]);
    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->pluck('price_list_id')->unique()->all())->toBe([$own->id]);

    $own->update(['archived_at' => now()]);
    expect(fn () => ($this->plan)(['base' => false, 'price_group_ids' => [$group->id]]))->toThrow(ValidationException::class);
});

it('checks suppliers inside the company even when none is current', function () {
    CurrentCompany::forget();

    $run = ($this->plan)(['supplier_ids' => [$this->supplierA->id]]);

    expect($run->scope['supplier_ids'])->toBe([$this->supplierA->id]);
});

it('refuses a margin to a user with price-lists:manage but without prices:view-cost, and does not offer it', function () {
    $user = new class extends User
    {
        public function getForeignKey(): string
        {
            return 'user_id';
        }

        public function getMorphClass(): string
        {
            return User::class;
        }

        public function hasTeamPermission($team, string $permission): bool
        {
            return $permission !== 'prices:view-cost' && parent::hasTeamPermission($team, $permission);
        }
    };
    $user->setRawAttributes($this->owner->fresh()->getAttributes(), true);
    $user->exists = true;

    expect(fn () => app(PlanPriceUpdate::class)($user, $this->company, ['kind' => 'MARGIN', 'margin' => '30', 'base' => true, 'effective_date' => '2026-06-15']))
        ->toThrow(AuthorizationException::class)
        ->and(PriceUpdateRun::query()->count())->toBe(0);

    $this->actingAs($user);
    Livewire::test(PriceUpdates::class)->assertDontSee('Mål för täckningsgrad')->assertSee('Ändring i procent');
});
