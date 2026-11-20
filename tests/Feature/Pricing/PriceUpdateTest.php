<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\ApplyPriceUpdate;
use App\Actions\Pricing\PlanPriceUpdate;
use App\Actions\Pricing\RevertPriceUpdate;
use App\Domain\Pricing\PriceChangeSource;
use App\Domain\Pricing\PriceFile;
use App\Domain\Pricing\PriceListExport;
use App\Domain\Pricing\PriceUpdateLineStatus;
use App\Domain\Pricing\PriceUpdateRefusal;
use App\Domain\Pricing\PriceUpdateStatus;
use App\Models\Article;
use App\Models\ArticlePriceHistory;
use App\Models\AuditLog;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use App\Models\Unit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * ADR-082 §8, P6: a mass price update is previewed before anything is
 * written, never edits a started list price (a new dated item instead),
 * writes future base prices on their date, can be reverted until that date
 * without deleting history, and reads and writes the list file format.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    $save = app(SaveArticle::class);
    $this->screw = $save($this->owner, $this->company, ['number' => '1001', 'name' => 'Skruv', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    $this->nail = $save($this->owner, $this->company, ['number' => '1002', 'name' => 'Spik', 'unit' => 'st', 'price' => '0,30', 'vat_rate' => 2500]);
    $this->drill = $save($this->owner, $this->company, ['number' => '2001', 'name' => 'Borr', 'unit' => 'st', 'price' => '500', 'vat_rate' => 2500]);

    $this->list = PriceList::create(['code' => 'ATER', 'name' => 'Återförsäljare', 'created_by' => $this->owner->id]);
    $this->started = PriceListItem::create(['price_list_id' => $this->list->id, 'article_id' => $this->screw->id, 'price_minor' => 9_000, 'valid_from' => '2026-01-01']);

    $this->plan = fn (array $input, ?UploadedFile $file = null): PriceUpdateRun => app(PlanPriceUpdate::class)($this->owner, $this->company, [
        'kind' => 'PERCENT', 'base' => false, 'list_ids' => [], 'articles' => 'all', 'rounding' => 'NONE', 'effective_date' => '2026-06-15', ...$input,
    ], $file);
    $this->apply = fn (PriceUpdateRun $run, array $excluded = []): PriceUpdateRun => app(ApplyPriceUpdate::class)($this->owner, $this->company, $run, $excluded);
    $this->revert = fn (PriceUpdateRun $run): PriceUpdateRun => app(RevertPriceUpdate::class)($this->owner, $this->company, $run);
});

function p6Line(PriceUpdateRun $run, int $articleId, string $target = 'BASE'): PriceUpdateRunLine
{
    return PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->where('article_id', $articleId)->where('target', $target)->firstOrFail();
}

function p6Csv(string ...$lines): UploadedFile
{
    return UploadedFile::fake()->createWithContent('priser.csv', implode("\r\n", $lines));
}

it('previews a percentage on base prices and a list without writing anything', function () {
    $run = ($this->plan)(['percent' => '4', 'base' => true, 'list_ids' => [$this->list->id]]);

    expect($run->status)->toBe(PriceUpdateStatus::Preview)
        ->and($run->counts)->toMatchArray(['lines' => 4, 'ready' => 4])
        ->and(p6Line($run, $this->screw->id)->new_minor)->toBe(10_400)
        ->and(p6Line($run, $this->screw->id, 'LIST')->new_minor)->toBe(9_360)
        ->and(p6Line($run, $this->screw->id, 'LIST')->current_item_id)->toBe($this->started->id)
        ->and(Article::query()->find($this->screw->id)->price_ore->ore)->toBe(10_000)
        ->and(PriceListItem::query()->count())->toBe(1);

    $audit = AuditLog::query()->where('action', 'price_update.planned')->latest('id')->first();
    expect(array_keys($audit->payload))->toEqualCanonicalizing(['kind', 'price_list_ids', 'base', 'counts']);
});

it('applies today: base prices with history, and a new list item instead of editing the started one', function () {
    $run = ($this->apply)(($this->plan)(['percent' => '4', 'base' => true, 'list_ids' => [$this->list->id]]));

    expect($run->status)->toBe(PriceUpdateStatus::Applied)
        ->and($run->counts['applied'])->toBe(4)
        ->and(Article::query()->find($this->screw->id)->price_ore->ore)->toBe(10_400);

    $history = ArticlePriceHistory::query()->where('article_id', $this->screw->id)->latest('id')->first();
    expect($history->source)->toBe(PriceChangeSource::PriceUpdate)
        ->and($history->price_update_run_id)->toBe($run->id)
        ->and([$history->old_price_ore, $history->new_price_ore])->toBe([10_000, 10_400]);

    $this->started->refresh();
    $new = PriceListItem::query()->where('price_update_run_id', $run->id)->sole();

    expect($this->started->price_minor)->toBe(9_000)
        ->and($this->started->valid_to->toDateString())->toBe('2026-06-14')
        ->and($new->price_minor)->toBe(9_360)
        ->and($new->valid_from->toDateString())->toBe('2026-06-15')
        ->and($new->valid_to)->toBeNull();
});

it('rounds by the chosen rule and never rounds a positive price to zero', function () {
    $run = ($this->plan)(['percent' => '-50', 'base' => true, 'rounding' => 'WHOLE']);

    $nail = p6Line($run, $this->nail->id);
    expect($nail->unrounded_minor)->toBe(15)
        ->and($nail->new_minor)->toBe(100)
        ->and($nail->flags)->toContain('rounding_moved', 'big_change')
        ->and(p6Line($run, $this->screw->id)->new_minor)->toBe(5_000);
});

it('computes an index adjustment from the figures typed and a fixed amount in kronor only', function () {
    $index = ($this->plan)(['kind' => 'INDEX', 'index_old' => '400', 'index_new' => '412,5', 'base' => true]);
    expect(p6Line($index, $this->screw->id)->new_minor)->toBe(10_313)
        ->and($index->rule)->toBe(['index_old' => '400', 'index_new' => '412.5']);

    $eur = PriceList::create(['code' => 'EUR', 'name' => 'Euro', 'currency' => 'EUR', 'created_by' => $this->owner->id]);
    PriceListItem::create(['price_list_id' => $eur->id, 'article_id' => $this->screw->id, 'price_minor' => 900, 'valid_from' => '2026-01-01']);

    $amount = ($this->plan)(['kind' => 'AMOUNT', 'amount' => '-1', 'base' => true, 'list_ids' => [$eur->id]]);
    expect(p6Line($amount, $this->nail->id)->reason)->toBe(PriceUpdateRefusal::NegativePrice)
        ->and(p6Line($amount, $this->screw->id)->new_minor)->toBe(9_900)
        ->and(p6Line($amount, $this->screw->id, 'LIST')->reason)->toBe(PriceUpdateRefusal::OtherCurrency);
});

it('explains why a discount item or a price starting the same day is not changed', function () {
    PriceListItem::create(['price_list_id' => $this->list->id, 'article_id' => $this->drill->id, 'discount_bp' => 1000, 'valid_from' => '2026-01-01']);
    PriceListItem::create(['price_list_id' => $this->list->id, 'article_id' => $this->nail->id, 'price_minor' => 25, 'valid_from' => '2026-07-01']);

    $run = ($this->plan)(['percent' => '4', 'list_ids' => [$this->list->id], 'effective_date' => '2026-07-01']);

    expect(p6Line($run, $this->drill->id, 'LIST')->reason)->toBe(PriceUpdateRefusal::DiscountItem)
        ->and(p6Line($run, $this->nail->id, 'LIST')->reason)->toBe(PriceUpdateRefusal::SameStart)
        ->and(p6Line($run, $this->screw->id, 'LIST')->status)->toBe(PriceUpdateLineStatus::Ready);
});

it('filters articles by number range, search and selection', function () {
    $range = ($this->plan)(['percent' => '1', 'base' => true, 'articles' => 'range', 'number_from' => '1000', 'number_to' => '1999']);
    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $range->id)->pluck('article_id')->sort()->values()->all())->toBe([$this->screw->id, $this->nail->id]);

    $search = ($this->plan)(['percent' => '1', 'base' => true, 'articles' => 'search', 'search' => 'borr']);
    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $search->id)->pluck('article_id')->all())->toBe([$this->drill->id]);

    $selected = ($this->plan)(['percent' => '1', 'base' => true, 'articles' => 'selected', 'article_ids' => [$this->nail->id]]);
    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $selected->id)->pluck('article_id')->all())->toBe([$this->nail->id]);
});

it('schedules future base prices, writes them on their date once, and lists new items ahead', function () {
    $run = ($this->apply)(($this->plan)(['percent' => '10', 'base' => true, 'list_ids' => [$this->list->id], 'effective_date' => '2026-07-01']));

    expect($run->status)->toBe(PriceUpdateStatus::Scheduled)
        ->and($run->counts)->toMatchArray(['applied' => 1, 'scheduled' => 3])
        ->and(Article::query()->find($this->screw->id)->price_ore->ore)->toBe(10_000)
        ->and(PriceListItem::query()->where('price_update_run_id', $run->id)->sole()->valid_from->toDateString())->toBe('2026-07-01')
        ->and($this->started->refresh()->valid_to->toDateString())->toBe('2026-06-30');

    $this->artisan('pricing:apply-scheduled')->assertSuccessful();
    expect(Article::query()->find($this->screw->id)->price_ore->ore)->toBe(10_000);

    $this->travelTo(CarbonImmutable::parse('2026-07-01 00:10:00'));
    $this->artisan('pricing:apply-scheduled')->assertSuccessful();
    $this->artisan('pricing:apply-scheduled')->assertSuccessful();

    expect(Article::query()->find($this->screw->id)->price_ore->ore)->toBe(11_000)
        ->and($run->refresh()->status)->toBe(PriceUpdateStatus::Applied)
        ->and(ArticlePriceHistory::query()->where('price_update_run_id', $run->id)->count())->toBe(3)
        ->and(ArticlePriceHistory::query()->where('price_update_run_id', $run->id)->whereNull('changed_by')->count())->toBe(3);
});

it('skips a price that changed after the preview and leaves out excluded lines', function () {
    $run = ($this->plan)(['percent' => '10', 'base' => true]);

    app(SaveArticle::class)($this->owner, $this->company, ['number' => '1001', 'name' => 'Skruv', 'unit' => 'st', 'price' => '120', 'vat_rate' => 2500], $this->screw);

    $run = ($this->apply)($run, [p6Line($run, $this->drill->id)->id]);

    expect($run->counts)->toMatchArray(['applied' => 1, 'skipped' => 1, 'excluded' => 1])
        ->and(Article::query()->find($this->screw->id)->price_ore->ore)->toBe(12_000)
        ->and(Article::query()->find($this->drill->id)->price_ore->ore)->toBe(50_000)
        ->and(Article::query()->find($this->nail->id)->price_ore->ore)->toBe(33)
        ->and(p6Line($run, $this->screw->id)->reason)->toBe(PriceUpdateRefusal::ChangedSince);
});

it('reverts until the effective date without deleting history, and refuses afterwards', function () {
    $run = ($this->apply)(($this->plan)(['percent' => '10', 'base' => true, 'list_ids' => [$this->list->id]]));
    $made = PriceListItem::query()->where('price_update_run_id', $run->id)->sole();

    $run = ($this->revert)($run);

    expect($run->status)->toBe(PriceUpdateStatus::Reverted)
        ->and(Article::query()->find($this->screw->id)->price_ore->ore)->toBe(10_000)
        ->and($made->refresh()->archived_at)->not->toBeNull()
        ->and($this->started->refresh()->valid_to)->toBeNull()
        ->and(PriceListItem::query()->count())->toBe(2)
        ->and(ArticlePriceHistory::query()->where('price_update_run_id', $run->id)->count())->toBe(6)
        ->and(PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->where('status', 'REVERTED')->count())->toBe(4);

    expect(fn () => ($this->revert)($run))->toThrow(ValidationException::class);

    $later = ($this->apply)(($this->plan)(['percent' => '5', 'base' => true]));
    $this->travelTo(CarbonImmutable::parse('2026-06-16 08:00:00'));

    expect(fn () => ($this->revert)($later))->toThrow(ValidationException::class);
});

it('reverts a scheduled run before its date without writing the waiting base prices', function () {
    $run = ($this->apply)(($this->plan)(['percent' => '10', 'base' => true, 'effective_date' => '2026-07-01']));
    ($this->revert)($run);

    $this->travelTo(CarbonImmutable::parse('2026-07-01 00:10:00'));
    $this->artisan('pricing:apply-scheduled')->assertSuccessful();

    expect(Article::query()->find($this->screw->id)->price_ore->ore)->toBe(10_000)
        ->and(ArticlePriceHistory::query()->where('price_update_run_id', $run->id)->count())->toBe(0);
});

it('reads a price file, explains every refused row and writes nothing before Genomför', function () {
    $metre = Unit::query()->where('code', 'm')->first();
    $file = p6Csv(
        'Artikelnummer;Benämning;Enhet;Från antal;Pris;Rabatt %;Gäller från',
        '1001;Skruv;;;"1 095,50";;',
        '1001;Skruv;;10;85;;',
        '1001;igen;;10;84;;',
        '9999;Okänd;;;10;;',
        '1002;Spik;;;abc;;',
        '1002;Spik;;;-5;;',
        '1002;Spik;;5;;;',
        '2001;Borr;'.($metre?->code ?? 'm').';;10;;',
        '2001;Borr;;;;12,5;2026-01-01',
        '2001;Borr;;;;12,5;2026-08-01',
    );

    $run = ($this->plan)(['kind' => 'FILE', 'list_ids' => [$this->list->id]], $file);
    $lines = PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->orderBy('position')->get()->keyBy('position');

    expect($run->file_sha256)->toHaveLength(64)
        ->and($run->file_format)->toBe('csv')
        ->and(Storage::disk('local')->exists($run->file_path))->toBeTrue()
        ->and($lines[2]->new_minor)->toBe(109_550)
        ->and($lines[2]->current_item_id)->toBe($this->started->id)
        ->and($lines[3]->status)->toBe(PriceUpdateLineStatus::Ready)
        ->and($lines[4]->reason)->toBe(PriceUpdateRefusal::Duplicate)
        ->and($lines[5]->reason)->toBe(PriceUpdateRefusal::UnknownArticle)
        ->and($lines[5]->article_number)->toBe('9999')
        ->and($lines[6]->reason)->toBe(PriceUpdateRefusal::BadPrice)
        ->and($lines[7]->reason)->toBe(PriceUpdateRefusal::NegativePrice)
        ->and($lines[8]->reason)->toBe(PriceUpdateRefusal::PriceOrDiscount)
        ->and($lines[9]->reason)->toBeIn([PriceUpdateRefusal::UnitNotConvertible, PriceUpdateRefusal::UnknownUnit])
        ->and($lines[10]->reason)->toBe(PriceUpdateRefusal::DateInPast)
        ->and($lines[11]->new_discount_bp)->toBe(1250)
        ->and($lines[11]->valid_from->toDateString())->toBe('2026-08-01')
        ->and(PriceListItem::query()->count())->toBe(1);

    $run = ($this->apply)($run);

    expect($run->counts['applied'])->toBe(3)
        ->and(PriceListItem::query()->where('price_update_run_id', $run->id)->count())->toBe(3)
        ->and(PriceListItem::query()->where('price_update_run_id', $run->id)->where('article_id', $this->drill->id)->sole()->discount_bp)->toBe(1250);
});

it('updates base prices from a file and refuses a discount or a ladder on a base price', function () {
    $run = ($this->plan)(['kind' => 'FILE', 'base' => true], p6Csv('Artikelnummer;Pris;Rabatt %;Från antal', '1001;110;;', '1002;;10;', '2001;450;;5'));
    $lines = PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->orderBy('position')->get();

    expect($lines->pluck('reason')->all())->toBe([null, PriceUpdateRefusal::DiscountOnBase, PriceUpdateRefusal::BaseHasNoLadder]);

    ($this->apply)($run);
    expect(Article::query()->find($this->screw->id)->price_ore->ore)->toBe(11_000);
});

it('refuses a file without the needed columns or with too many rows, and keeps no file then', function () {
    expect(fn () => ($this->plan)(['kind' => 'FILE', 'base' => true], p6Csv('Namn;Pris', 'x;1')))
        ->toThrow(ValidationException::class);

    $rows = ['Artikelnummer;Pris'];
    for ($i = 0; $i <= PriceFile::MAX_ROWS; $i++) {
        $rows[] = "1001;{$i}";
    }

    try {
        ($this->plan)(['kind' => 'FILE', 'base' => true], p6Csv(...$rows));
        $this->fail('A file with too many rows was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['file'][0])->toContain('fler än 10 000 rader');
    }

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(PriceUpdateRun::query()->count())->toBe(0);
});

it('exports a list in the file format, guarded against formulas, and reads its own export back', function () {
    $evil = app(SaveArticle::class)($this->owner, $this->company, ['number' => '3001', 'name' => '=HYPERLINK("x")', 'unit' => 'st', 'price' => '10', 'vat_rate' => 2500]);
    PriceListItem::create(['price_list_id' => $this->list->id, 'article_id' => $evil->id, 'price_minor' => 1_250, 'min_quantity_milli' => 2_500, 'valid_from' => '2026-01-01']);

    $csv = PriceListExport::csv($this->list);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Artikelnummer;Benämning;Enhet;"Från antal";Pris;"Rabatt %";"Gäller från";"Gäller till"')
        ->toContain('1001;Skruv;;;90,00;;2026-01-01;')
        ->toContain("'=HYPERLINK")
        ->toContain(';2,5;12,50;');

    $xlsx = PriceListExport::xlsx($this->list);
    $path = tempnam(sys_get_temp_dir(), 'p6').'.xlsx';
    file_put_contents($path, $xlsx);
    $rows = PriceFile::read($path);
    @unlink($path);

    expect(array_values($rows))->toBe([
        ['article' => '1001', 'unit' => '', 'quantity' => '', 'price' => '90', 'discount' => '', 'from' => '2026-01-01'],
        ['article' => '3001', 'unit' => '', 'quantity' => '2.5', 'price' => '12.5', 'discount' => '', 'from' => '2026-01-01'],
    ]);

    $run = ($this->plan)(['kind' => 'FILE', 'list_ids' => [$this->list->id], 'effective_date' => '2026-07-01'], UploadedFile::fake()->createWithContent('lista.xlsx', $xlsx));

    expect($run->counts)->toMatchArray(['unchanged' => 0, 'refused' => 2])
        ->and(PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->pluck('reason')->unique()->values()->all())->toBe([PriceUpdateRefusal::DateInPast]);
});

it('needs price-lists:manage to plan, apply and revert', function () {
    $run = ($this->plan)(['percent' => '1', 'base' => true]);
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => app(PlanPriceUpdate::class)($bookkeeper, $this->company, ['kind' => 'PERCENT', 'percent' => '1', 'base' => true, 'effective_date' => '2026-06-15']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApplyPriceUpdate::class)($bookkeeper, $this->company, $run))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevertPriceUpdate::class)($bookkeeper, $this->company, $run))->toThrow(AuthorizationException::class);
});

it('refuses another company\'s list, a date in the past and an empty selection', function () {
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $foreign = CurrentCompany::run($otherCompany, fn () => PriceList::create(['code' => 'HEMLIG', 'name' => 'Hemlig', 'created_by' => $otherOwner->id]));
    CurrentCompany::set($this->company);

    expect(fn () => ($this->plan)(['percent' => '1', 'list_ids' => [$foreign->id]]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->plan)(['percent' => '1', 'base' => true, 'effective_date' => '2026-06-14']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->plan)(['percent' => '1']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->plan)(['percent' => '0', 'base' => true]))->toThrow(ValidationException::class);
});

it('prunes an unapplied preview after seven days and a price file after two years, keeping the diff', function () {
    $preview = ($this->plan)(['percent' => '1', 'base' => true]);
    $file = ($this->plan)(['kind' => 'FILE', 'base' => true], p6Csv('Artikelnummer;Pris', '1001;110'));
    ($this->apply)($file);

    $this->travelTo(CarbonImmutable::parse('2026-06-23 10:00:00'));
    $this->artisan('pricing:prune-updates')->assertSuccessful();

    expect(PriceUpdateRunLine::query()->where('price_update_run_id', $preview->id)->count())->toBe(0)
        ->and($preview->refresh()->file_deleted_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $file->refresh()->file_path))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2028-06-16 10:00:00'));
    $this->artisan('pricing:prune-updates')->assertSuccessful();

    expect($file->refresh()->file_path)->toBeNull()
        ->and($file->file_deleted_at)->not->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(PriceUpdateRunLine::query()->where('price_update_run_id', $file->id)->count())->toBe(1);
});
