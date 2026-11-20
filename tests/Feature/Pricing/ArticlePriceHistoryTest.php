<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Domain\Pricing\PriceChangeSource;
use App\Models\Article;
use App\Models\ArticlePriceHistory;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

/*
 * ADR-082 §1 (P1): every change of an article's base price is kept in
 * article_price_history, with where it came from; ids, amounts and an enum
 * only, never a name. The history is append-only.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->travelTo(CarbonImmutable::parse('2026-03-04 10:00', 'Europe/Stockholm'));
});

/** @param  array<string, mixed>  $input */
function p1Article(object $test, array $input = [], ?Article $article = null, bool $import = false, ?PriceChangeSource $source = null): Article
{
    return app(SaveArticle::class)($test->owner, $test->company, [
        'number' => 'P1', 'name' => 'Hyllplan', 'unit' => 'st', 'price' => '149,00', 'vat_rate' => 2500, ...$input,
    ], $article, $import, $source);
}

it('records the first price of a new article, from the form, on today\'s date', function () {
    $article = p1Article($this);

    $entry = ArticlePriceHistory::query()->where('article_id', $article->id)->sole();

    expect($entry->old_price_ore)->toBeNull()
        ->and($entry->new_price_ore)->toBe(14_900)
        ->and($entry->source)->toBe(PriceChangeSource::Form)
        ->and($entry->valid_from->toDateString())->toBe('2026-03-04')
        ->and($entry->changed_by)->toBe($this->owner->id)
        ->and($entry->company_id)->toBe($this->company->id)
        ->and($entry->price_update_run_id)->toBeNull();
});

it('records a change of the price with the old and the new price', function () {
    $article = p1Article($this);
    p1Article($this, ['price' => '159,00'], $article);

    expect($article->priceHistory()->get()->map(fn (ArticlePriceHistory $entry): array => [$entry->old_price_ore, $entry->new_price_ore])->all())
        ->toBe([[null, 14_900], [14_900, 15_900]]);
});

it('records nothing when the price stays the same, also when other fields change', function () {
    $article = p1Article($this);
    p1Article($this, ['name' => 'Hyllplan ek', 'price' => '149'], $article);
    p1Article($this, ['vat_rate' => 1200], $article);

    expect(ArticlePriceHistory::query()->where('article_id', $article->id)->count())->toBe(1);
});

it('marks a change from an import, and lets the caller name the API or a price update', function () {
    $imported = p1Article($this, ['number' => 'IMP'], import: true);
    $byApi = p1Article($this, ['number' => 'API'], source: PriceChangeSource::Api);

    expect(ArticlePriceHistory::query()->where('article_id', $imported->id)->sole()->source)->toBe(PriceChangeSource::Import)
        ->and(ArticlePriceHistory::query()->where('article_id', $byApi->id)->sole()->source)->toBe(PriceChangeSource::Api);
});

it('marks a change made through the API as API', function () {
    Connect::setUp();
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);

    $id = $this->withToken($key)->withHeader('Idempotency-Key', 'p1-1')
        ->postJson('/api/v1/articles', ['number' => 'B1', 'name' => 'Bräda', 'unit' => 'st', 'price_ore' => 10_000, 'vat_rate' => 2500])
        ->assertCreated()->json('data.id');
    $this->withToken($key)->patchJson("/api/v1/articles/{$id}", ['price_ore' => 12_500])->assertOk();

    $entries = CurrentCompany::run($company, fn () => ArticlePriceHistory::query()->where('article_id', $id)->orderBy('id')->get());

    expect($entries->map(fn (ArticlePriceHistory $entry): array => [$entry->old_price_ore, $entry->new_price_ore, $entry->source])->all())
        ->toBe([[null, 10_000, PriceChangeSource::Api], [10_000, 12_500, PriceChangeSource::Api]]);
});

it('keeps no names or free text in the history', function () {
    expect(Schema::getColumnListing('article_price_history'))->toEqualCanonicalizing([
        'id', 'company_id', 'article_id', 'old_price_ore', 'new_price_ore', 'valid_from', 'source', 'price_update_run_id', 'changed_by', 'created_at',
    ]);
});

it('never changes or deletes a history entry, through the model or the database', function () {
    $entry = ArticlePriceHistory::query()->where('article_id', p1Article($this)->id)->sole();

    expect(fn () => $entry->update(['new_price_ore' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('article_price_history')->where('id', $entry->id)->update(['new_price_ore' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('article_price_history')->where('id', $entry->id)->delete()))->toThrow(QueryException::class)
        ->and($entry->fresh()->new_price_ore)->toBe(14_900);
});

it('rolls the history back with the article when saving fails', function () {
    $article = p1Article($this);

    expect(fn () => p1Article($this, ['price' => '199', 'account' => 3999], $article))->toThrow(ValidationException::class);

    expect($article->fresh()->price_ore->ore)->toBe(14_900)
        ->and(ArticlePriceHistory::query()->where('article_id', $article->id)->count())->toBe(1);
});
