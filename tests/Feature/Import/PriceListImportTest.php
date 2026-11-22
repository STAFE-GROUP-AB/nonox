<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Migration\Import\RegisterKind;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\RegisterImport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * ADR-082 P10 and ADR-076: the register import kind PriceLists. Two steps,
 * like every kind: the preview names what each row does, nothing is written
 * until the file is confirmed.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    foreach (['A-1' => 'Skruv', 'A-2' => 'Mutter'] as $number => $name) {
        app(SaveArticle::class)($this->owner, $this->company, ['number' => $number, 'name' => $name, 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    }

    $this->header = "Prislistans kod;Valuta;Artikelnummer;Från antal;Pris;Gäller från\n";
    $this->run = fn (string $csv) => RegisterImports::run($this->owner, $this->company, RegisterKind::PriceLists, RegisterImports::contents($this->header.$csv));
    $this->items = fn () => CurrentCompany::run($this->company, fn () => PriceListItem::query()->orderBy('id')->get());
});

it('reads a price file in two steps and writes nothing until it is confirmed', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::PriceLists, RegisterImports::contents($this->header."ATER;SEK;A-1;;90,00;\n"));
    $import = RegisterImports::analyse($this->owner, $import);

    expect($import->report['counts'])->toMatchArray(['read' => 1, 'create' => 1, 'error' => 0])
        ->and($import->canBeConfirmed())->toBeTrue()
        ->and(($this->items)())->toHaveCount(0)
        ->and(CurrentCompany::run($this->company, fn () => PriceList::query()->count()))->toBe(0);
});

it('creates the list and a quantity ladder with prices in öre', function () {
    $import = ($this->run)("ATER;sek;A-1;;90,00;\nATER;SEK;A-1;10;80,50;\nATER;SEK;A-2;2,5;1 234,00;\n");
    $list = CurrentCompany::run($this->company, fn () => PriceList::query()->where('code', 'ATER')->firstOrFail());
    $items = ($this->items)();

    expect($import->status)->toBe(RegisterImport::COMPLETED)
        ->and($import->result)->toMatchArray(['created' => 3, 'error_count' => 0])
        ->and($list->currency)->toBe('SEK')
        ->and($items->pluck('price_minor')->all())->toBe([9000, 8050, 123400])
        ->and($items->pluck('min_quantity_milli')->all())->toBe([0, 10000, 2500]);
});

it('puts prices on an existing list and skips a price it already has', function () {
    ($this->run)("ATER;SEK;A-1;;90,00;2026-01-01\n");
    $again = ($this->run)("ATER;SEK;A-1;;90,00;2026-01-01\nATER;SEK;A-2;;50,00;2026-01-01\n");

    expect($again->report['counts'])->toMatchArray(['exists' => 1, 'create' => 1])
        ->and($again->result)->toMatchArray(['created' => 1, 'skipped' => 1])
        ->and(($this->items)())->toHaveCount(2)
        ->and(CurrentCompany::run($this->company, fn () => PriceList::query()->count()))->toBe(1);
});

it('lets a later start end the running price instead of overlapping it', function () {
    ($this->run)("ATER;SEK;A-1;;90,00;2026-01-01\n");
    ($this->run)("ATER;SEK;A-1;;95,00;2026-09-01\nATER;SEK;A-1;;99,00;2026-12-01\n");
    $items = ($this->items)();

    expect($items->pluck('price_minor')->all())->toBe([9000, 9500, 9900])
        ->and($items[0]->valid_to->toDateString())->toBe('2026-08-31')
        ->and($items[1]->valid_to->toDateString())->toBe('2026-11-30')
        ->and($items[2]->valid_to)->toBeNull();
});

it('names every problem row and still reads the good ones', function () {
    $import = ($this->run)(implode("\n", [
        'ATER;SEK;A-1;;90,00;',
        'ATER;SEK;SAKNAS;;10,00;',
        'ATER;SEK;A-2;;1,234;',
        'ATER;EUR;A-2;;5,00;',
        'ATER;SEK;A-2;;-5,00;',
        'ATER;SEK;A-2;tio;5,00;',
        'ATER;SEK;A-2;;5,00;2026-13-40',
        'ATER;SEK;A-1;;80,00;',
        'ATER;SEK;A-1;;90,00;',
        '',
    ]));
    $rows = collect($import->report['rows'])->keyBy('line');

    expect($import->report['counts'])->toMatchArray(['read' => 9, 'create' => 1, 'error' => 7, 'exists' => 1])
        ->and($rows[3]['errors'][0])->toContain('SAKNAS')
        ->and($rows[4]['errors'][0])->toContain('två sätt')
        ->and($rows[5]['errors'][0])->toContain('EUR')
        ->and($rows[6]['errors'][0])->toContain('negativt')
        ->and($rows[7]['errors'][0])->toContain('ett tal')
        ->and($rows[8]['errors'][0])->toContain('ÅÅÅÅ-MM-DD')
        ->and($rows[9]['errors'][0])->toContain('gäller samtidigt')
        ->and(($this->items)())->toHaveCount(1);
});

it('refuses an archived list and a list with another currency', function () {
    ($this->run)("ATER;SEK;A-1;;90,00;\n");
    $import = ($this->run)("ATER;EUR;A-2;;9,00;\n");

    expect($import->report['counts']['error'])->toBe(1)
        ->and($import->report['rows'][0]['errors'][0])->toContain('SEK');

    CurrentCompany::run($this->company, fn () => PriceList::query()->where('code', 'ATER')->update(['archived_at' => now()]));
    $archived = ($this->run)("ATER;SEK;A-2;;9,00;\n");

    expect($archived->report['rows'][0]['errors'][0])->toContain('arkiverad');
});

it('blocks a file without the required columns', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::PriceLists, RegisterImports::contents("Kod;Belopp\nATER;5\n"));
    $import = RegisterImports::analyse($this->owner, $import);

    expect($import->report['blockers'])->not->toBe([])
        ->and($import->canBeConfirmed())->toBeFalse();
});

it('needs the permission to manage price lists, not only to import', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => RegisterImports::upload($bookkeeper, $this->company, RegisterKind::PriceLists, RegisterImports::contents($this->header."ATER;SEK;A-1;;90,00;\n")))
        ->toThrow(AuthorizationException::class);
});

it('keeps the number of queries the same for a long file', function () {
    $count = function (int $rows): int {
        $csv = $this->header.collect(range(1, $rows))->map(fn (int $i): string => "L{$i};SEK;A-1;;{$i},00;")->implode("\n")."\n";
        $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::PriceLists, RegisterImports::contents($csv));
        DB::flushQueryLog();
        DB::enableQueryLog();
        RegisterImports::analyse($this->owner, $import);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(30))->toBe($count(3));
});

it('never sees another company\'s articles or lists', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::run($other, function () use ($otherOwner, $other) {
        app(SaveArticle::class)($otherOwner, $other, ['number' => 'X-9', 'name' => 'Hemlig', 'unit' => 'st', 'price' => '5', 'vat_rate' => 2500]);
        PriceList::create(['code' => 'HEMLIG', 'name' => 'Hemlig', 'currency' => 'EUR', 'created_by' => $otherOwner->id]);
    });

    $import = ($this->run)("ATER;SEK;X-9;;5,00;\nHEMLIG;SEK;A-1;;5,00;\n");

    expect($import->report['counts'])->toMatchArray(['error' => 1, 'create' => 1])
        ->and($import->report['rows'][0]['errors'][0])->toContain('X-9')
        ->and(CurrentCompany::run($this->company, fn () => PriceList::query()->pluck('code')->sort()->values()->all()))->toBe(['HEMLIG'])
        ->and(CurrentCompany::run($other, fn () => PriceListItem::query()->count()))->toBe(0);
});
