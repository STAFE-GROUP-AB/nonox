<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Pricing\ArticleCostBook;
use App\Domain\Pricing\DocumentMargin;
use App\Domain\Pricing\PriceFloorGuard;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Manufacturing;

/*
 * ADR-082 §9 (P7): the cost of a row is the costing of ADR-071 §6. With
 * Tillverkning on, an article with a bill of materials costs what that bill
 * costs, a bought article its purchase price, and a row in a unit the article
 * converts to is costed in that unit.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
});

it('costs an article with a bill of materials as the manufacturing costing does', function () {
    $table = $this->fx['articles']['table'];
    $book = ArticleCostBook::for($this->company, [$table->id]);

    // 384 412 material + 120 500 labour, as in Tests\Support\Manufacturing.
    expect($book->costOf($table->id, '1', 'st'))->toBe(504_912)
        ->and($book->costOf($table->id, '2', 'st'))->toBe(1_009_824);
});

it('costs a bought article from its purchase price and converts the unit', function () {
    $leg = $this->fx['articles']['leg'];
    $top = $this->fx['articles']['top'];
    $book = ArticleCostBook::for($this->company, [$leg->id, $top->id]);

    expect($book->costOf($leg->id, '4', 'st'))->toBe(140_000)
        // 1 450,00 per m²; 50 dm² is half a square metre.
        ->and($book->costOf($top->id, '1', 'm²'))->toBe(145_000)
        ->and($book->costOf($top->id, '50', 'dm²'))->toBe(72_500);
});

it('puts the table under its floor when it is sold for less than its cost plus the margin', function () {
    $table = $this->fx['articles']['table'];
    $this->company->forceFill(['min_contribution_bp' => 2000])->save();

    $margin = DocumentMargin::assess($this->company->fresh(), [
        ['article_id' => $table->id, 'quantity' => '1', 'unit' => 'st', 'net_ore' => 600_000, 'manual' => true],
        ['article_id' => $table->id, 'quantity' => '1', 'unit' => 'st', 'net_ore' => 630_000, 'manual' => true],
    ]);

    // Cost 504 912: 600 000 is a margin of 15,9 %, 630 000 of 19,9 %: both are under 20 %.
    expect($margin->manualBelowFloor)->toBe([0, 1])
        ->and($margin->rows[0]->belowCost)->toBeFalse()
        ->and($margin->contributionOre())->toBe(1_230_000 - 1_009_824);
});

/**
 * Checks a draft with 1 and with 60 manual rows under a floor of 20 %, and counts the queries of PriceFloorGuard::state.
 *
 * @return array{0: int, 1: int}
 */
function m7QueryCounts(bool $manufacturing): array
{
    ['owner' => $owner, 'company' => $company] = Manufacturing::company($manufacturing);
    test()->actingAs($owner);
    $article = $manufacturing
        ? Manufacturing::fixtures($company, $owner)['articles']['table']
        : app(SaveArticle::class)($owner, $company, ['number' => 'LEG', 'name' => 'Ben', 'unit' => 'st', 'price' => '0,00', 'vat_rate' => 2500, 'purchase_price' => '350,00', 'is_stock_item' => true]);
    $company->forceFill(['min_contribution_bp' => 2000, 'price_floor_policy' => PriceFloorPolicy::RequireApproval])->save();
    $company = $company->fresh();
    $customer = app(SaveCustomer::class)($owner, $company, ['name' => 'Kunden AB', 'payment_terms_days' => 30]);
    $price = $manufacturing ? '6000,00' : '400,00';
    $copies = [];

    foreach (range(1, 60) as $i) {
        $copy = $article->replicate();
        $copy->number = "M{$i}";
        $copy->save();
        $copies[] = $copy->number;
    }

    $draft = fn (array $numbers) => app(SaveDraftInvoice::class)($owner, $company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-11-10',
        'rows' => array_map(fn (string $number): array => ['article' => $number, 'description' => $number, 'quantity' => '1', 'price' => $price, 'price_source' => 'MANUAL', 'vat_rate' => 2500], $numbers),
    ]);

    $short = $draft(array_slice($copies, 0, 1))->fresh();
    $long = $draft($copies)->fresh();

    $count = function (Invoice $invoice) use ($company): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        PriceFloorGuard::state($company, $invoice);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    return [$count($short), $count($long)];
}

it('checks a document in the same number of queries for 1 and 60 rows with Lager on', function () {
    [$short, $long] = m7QueryCounts(false);

    expect($long)->toBe($short);
});

it('checks a document in the same number of queries for 1 and 60 rows with Tillverkning on', function () {
    [$short, $long] = m7QueryCounts(true);

    expect($long)->toBe($short);
});
