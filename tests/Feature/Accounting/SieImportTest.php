<?php

declare(strict_types=1);

use App\Actions\Accounting\AnalyseSieFile;
use App\Actions\Accounting\ConfirmSieImport;
use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\ExportSie;
use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Accounting\MoveSieImportToCompany;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\LedgerVerifier;
use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Domain\Accounting\VoucherSource;
use App\Livewire\Accounting\SieImportReport;
use App\Livewire\Company\CreateCompanyForm;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\ImportedVoucher;
use App\Models\OpeningBalance;
use App\Models\Project;
use App\Models\SieImport;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRowHistory;
use App\Models\VoucherSeries;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

const SIE_IMPORT_FIXTURE = __DIR__.'/../../Fixtures/sie/import-2026.se';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['org_number' => '556334-3689'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

function sieUpload(string $contents, string $name = 'bokforing.se'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

/**
 * A minimal SIE 4 file for one year, without a control sum. Lines are
 * written as given, in codepage 437.
 *
 * @param  list<string>  $lines
 */
function sieImportFile(array $lines, string $year = '2026', string $organisationNumber = '556334-3689', string $type = '4'): string
{
    $header = [
        '#FLAGGA 0',
        '#FORMAT PC8',
        "#SIETYP {$type}",
        "#ORGNR {$organisationNumber}",
        "#RAR 0 {$year}0101 {$year}1231",
    ];

    return (string) iconv('UTF-8', 'CP437', implode("\r\n", [...$header, ...$lines])."\r\n");
}

/** @param list<string> $rows */
function sieImportVoucher(string $series, int $number, string $date, array $rows): string
{
    return "#VER {$series} {$number} {$date} \"Test\"\r\n{\r\n".implode("\r\n", $rows)."\r\n}";
}

function analyseSie(User $user, Company $company, string $contents): SieImport
{
    return app(AnalyseSieFile::class)($user, $company, sieUpload($contents));
}

function importSieFile(User $user, Company $company, string $contents): SieImport
{
    $import = analyseSie($user, $company, $contents);
    app(ConfirmSieImport::class)($user, $import);

    return $import->fresh();
}

function exportSieYear(User $user, FiscalYear $year): string
{
    $stream = fopen('php://memory', 'w+b');
    app(ExportSie::class)($user, $year, $stream);
    rewind($stream);

    return (string) stream_get_contents($stream);
}

it('reports what the file holds before anything is changed', function () {
    $import = analyseSie($this->owner, $this->company, (string) file_get_contents(SIE_IMPORT_FIXTURE));
    $report = $import->report;

    expect($import->status)->toBe(SieImportStatus::Analysed)
        ->and($import->blockers())->toBe([])
        ->and($import->canBeConfirmed())->toBeTrue()
        ->and($report['checksum'])->toBe('valid')
        ->and($report['program'])->toBe('Annat bokföringsprogram 3.1')
        ->and($report['fiscal_year'])->toMatchArray(['label' => '2026', 'create' => false])
        ->and($report['vouchers'])->toBe(3)
        ->and($report['added_rows'])->toBe(1)
        ->and($report['removed_rows'])->toBe(1)
        ->and($report['accounts_to_create'])->toBe([['number' => 6999, 'name' => 'Särskilda avgifter']])
        ->and($report['unused_accounts'])->toBe(1)
        ->and($report['series'])->toBe([
            ['code' => 'A', 'vouchers' => 2, 'create' => false, 'keep' => true, 'first' => 1],
            ['code' => 'K', 'vouchers' => 1, 'create' => true, 'keep' => false, 'first' => 1],
        ])
        ->and($report['opening_balances'])->toBe(2)
        ->and($report['write_opening_balances'])->toBeTrue()
        ->and($report['cost_centers_to_create'])->toBe([
            ['code' => '10', 'name' => 'Restaurang Malmö'],
            ['code' => '20', 'name' => 'Restaurang Lund'],
            ['code' => '30', 'name' => '30'],
        ])
        ->and($report['projects_to_create'])->toBe([['code' => 'P1', 'name' => 'Ombyggnad kök']])
        ->and(implode(' ', $report['warnings']))->toContain('Filen har dimension 7. Här finns bara kostnadsställen (1) och projekt (6), så 1 rader läses in utan de objekten.');

    expect(Voucher::count())->toBe(0)
        ->and(Account::query()->where('number', 6999)->exists())->toBeFalse()
        ->and(VoucherSeries::query()->where('code', 'K')->exists())->toBeFalse()
        ->and(AuditLog::forCompany($this->company)->where('action', 'sie.analysed')->count())->toBe(1);

    Storage::disk('local')->assertExists($import->file_path);
});

it('reads the file in as posted vouchers that reconcile with the file', function () {
    $import = importSieFile($this->owner, $this->company, (string) file_get_contents(SIE_IMPORT_FIXTURE));

    expect($import->status)->toBe(SieImportStatus::Completed)
        ->and($import->result)->toMatchArray([
            'ok' => true,
            'vouchers_in_file' => 3,
            'vouchers_imported' => 3,
            'checked_closing' => true,
            'checked_results' => true,
            'differences' => [],
        ]);

    $vouchers = Voucher::query()->with(['series', 'rows.account', 'rows.costCenter', 'rows.project', 'importedFrom'])->orderBy('posted_at')->orderBy('created_at')->get();

    expect($vouchers->map->reference()->sort()->values()->all())->toBe(['A1', 'A2', 'K1'])
        ->and($vouchers->every(fn (Voucher $voucher) => $voucher->isPosted() && $voucher->source === VoucherSource::ImportSie))->toBeTrue();

    $k1 = $vouchers->first(fn (Voucher $voucher) => $voucher->reference() === 'K1');
    expect($k1->importedFrom->originalReference())->toBe('K5');

    $a1 = $vouchers->first(fn (Voucher $voucher) => $voucher->reference() === 'A1');
    expect($a1->description)->toBe('Faktura 1 - Kund i Öresund AB')
        ->and($a1->rows->pluck('description')->all())->toBe([null, 'Konsulttjänst "Projekt Ö"', null])
        ->and($a1->importedFrom->registered_on->toDateString())->toBe('2026-01-11')
        ->and($a1->importedFrom->signature)->toBe('KK');

    expect($a1->rows[1]->costCenter->label())->toBe('10 Restaurang Malmö')
        ->and($a1->rows[1]->project->label())->toBe('P1 Ombyggnad kök')
        ->and($a1->rows[0]->cost_center_id)->toBeNull()
        ->and($k1->rows[0]->costCenter->code)->toBe('30')
        ->and(CostCenter::query()->orderBy('code')->pluck('code')->all())->toBe(['10', '20', '30']);

    $a2 = $vouchers->first(fn (Voucher $voucher) => $voucher->reference() === 'A2');
    expect($a2->rows->map(fn ($row) => [$row->account->number, $row->debit_ore->ore, $row->credit_ore->ore])->all())
        ->toBe([[1930, 125_000, 0], [1510, 0, 125_000]]);

    $history = VoucherRowHistory::query()->where('voucher_id', $a2->id)->orderBy('id')->get();
    expect($history->map(fn ($entry) => [$entry->change, $entry->position, $entry->account_number, $entry->amount_ore->ore])->all())
        ->toBe([['ADDED', 1, 1510, -125_000], ['REMOVED', 1, 1930, 120_500]])
        ->and($history->first()->voucher_row_id)->toBe($a2->rows[1]->id);

    expect(Account::query()->where('number', 6999)->sole()->sru_code)->toBe('7515')
        ->and(VoucherSeries::query()->where('code', 'K')->sole()->name)->toBe('Inläst serie K')
        ->and(OpeningBalance::count())->toBe(2)
        ->and(app(LedgerVerifier::class)->verify($this->company)['problems'])->toBe([])
        ->and(AuditLog::forCompany($this->company)->where('action', 'sie.imported')->sole()->payload['vouchers'])->toBe(3);
});

it('continues the numbering after imported vouchers', function () {
    importSieFile($this->owner, $this->company, (string) file_get_contents(SIE_IMPORT_FIXTURE));

    expect(Bookkeeping::post($this->owner, $this->company, date: '2026-09-01')->reference())->toBe('A3')
        ->and(Bookkeeping::post($this->owner, $this->company, date: '2026-09-02', series: 'K')->reference())->toBe('K2');
});

it('writes imported vouchers back with the file\'s registration, signature and row history', function () {
    importSieFile($this->owner, $this->company, (string) file_get_contents(SIE_IMPORT_FIXTURE));

    $file = iconv('CP437', 'UTF-8', exportSieYear($this->owner, FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole()));

    expect($file)->toContain("#VER A 2 20260120 \"Inbetalning faktura 1\" 20260120 \"KK\"\r\n{\r\n"
        ."   #TRANS 1930 {} 1250.00\r\n"
        ."   #BTRANS 1930 {} 1205.00 20260120 \"Fel belopp\" \"\" \"KK\"\r\n"
        ."   #RTRANS 1510 {} -1250.00 20260121 \"\" \"\" \"KK\"\r\n"
        ."   #TRANS 1510 {} -1250.00 20260121\r\n}")
        ->and($file)->toContain('#VER K 1 20260201 "Avgift" 20260201'."\r\n{\r\n   #TRANS 6999 {1 \"30\"} 50.00\r\n")
        ->and($file)->toContain('#TRANS 3001 {1 "10" 6 "P1"} -1000.00 20260110 "Konsulttjänst \\"Projekt Ö\\""')
        ->and($file)->toContain("#DIM 1 \"Kostnadsställe\"\r\n#DIM 6 \"Projekt\"\r\n#OBJEKT 1 \"10\" \"Restaurang Malmö\"\r\n");
});

it('moves a whole bookkeeping to a fresh company without losing anything', function () {
    // The source company: two years, opening balances, a correction and two series.
    [$sourceOwner, $source] = Bookkeeping::company();
    CurrentCompany::set($source);
    $source->forceFill(['org_number' => '556123-4567', 'bas_version' => 'BAS 2026'])->save();

    FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    Bookkeeping::post($sourceOwner, $source, 5_000_000, 1930, 2081, '2025-02-01');
    Bookkeeping::post($sourceOwner, $source, 300_000, 6110, 1930, '2025-05-01');

    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => Account::query()->where('number', 1930)->value('id'), 'amount_ore' => 4_700_000]);
    OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => Account::query()->where('number', 2081)->value('id'), 'amount_ore' => -5_000_000]);
    OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => Account::query()->where('number', 2099)->value('id'), 'amount_ore' => 300_000]);

    CostCenter::create(['code' => '10', 'name' => 'Restaurang Malmö']);
    CostCenter::create(['code' => '20', 'name' => 'Restaurang Lund']);
    Project::create(['code' => 'P1', 'name' => 'Ombyggnad kök']);

    app(PostVoucher::class)($sourceOwner, app(SaveDraftVoucher::class)($sourceOwner, $source, [
        'voucher_date' => '2026-03-15',
        'description' => 'Faktura 1001 – Kund i Öresund AB',
        'series' => 'A',
        'rows' => [
            ['account' => 1930, 'debit_ore' => 125_000],
            ['account' => 3001, 'credit_ore' => 100_000, 'description' => 'Konsulttjänst "Projekt Ö"', 'cost_center' => '10', 'project' => 'P1'],
            ['account' => 2611, 'credit_ore' => 25_000, 'cost_center' => '10'],
        ],
    ]));
    $purchase = Bookkeeping::post($sourceOwner, $source, 50_000, 6110, 1930, '2026-04-02');
    Bookkeeping::post($sourceOwner, $source, 20_000, 1930, 3001, '2026-05-01', 'B');
    app(CorrectVoucher::class)($sourceOwner, $purchase, 'Fel konto');

    $previous = FiscalYear::query()->whereDate('start_date', '2025-01-01')->sole();
    $files = [2025 => exportSieYear($sourceOwner, $previous), 2026 => exportSieYear($sourceOwner, $year)];

    // The target company has the same organisation number, as a company moving between programs does.
    $source->forceFill(['org_number' => '556987-6542'])->save();
    CurrentCompany::set($this->company);
    $this->company->forceFill(['org_number' => '556123-4567'])->save();
    $this->owner->forceFill(['name' => $sourceOwner->name])->save();

    foreach ([2025, 2026] as $label) {
        expect(importSieFile($this->owner, $this->company, $files[$label])->status)->toBe(SieImportStatus::Completed);
    }

    expect(CostCenter::query()->orderBy('code')->pluck('name', 'code')->all())->toBe(['10' => 'Restaurang Malmö', '20' => 'Restaurang Lund'])
        ->and(Project::query()->pluck('name', 'code')->all())->toBe(['P1' => 'Ombyggnad kök']);

    // Everything but the identification lines must be byte for byte the same.
    $ledger = fn (string $file): string => (string) preg_replace('/^#(PROGRAM|GEN|KSUMMA|ORGNR|FNAMN|ADRESS|FTYP)\b.*\r\n/m', '', $file);

    foreach ([2025, 2026] as $label) {
        $year = FiscalYear::query()->whereDate('start_date', "{$label}-01-01")->sole();

        expect($ledger(exportSieYear($this->owner, $year)))->toBe($ledger($files[$label]));
    }

    expect(app(LedgerVerifier::class)->verify($this->company))->toMatchArray(['ok' => true, 'vouchers' => 6]);
});

it('refuses a file that cannot be read in, and says why', function (Closure $contents, string $reason) {
    $import = analyseSie($this->owner, $this->company, $contents());

    expect(implode(' ', $import->blockers()))->toContain($reason)
        ->and($import->canBeConfirmed())->toBeFalse()
        ->and(fn () => app(ConfirmSieImport::class)($this->owner, $import))->toThrow(AuthorizationException::class);
})->with([
    'unbalanced voucher' => [fn () => sieImportFile([sieImportVoucher('A', 1, '20260110', ['#TRANS 1930 {} 100.00', '#TRANS 3001 {} -90.00'])]), 'balanserar inte'],
    'one row' => [fn () => sieImportFile([sieImportVoucher('A', 1, '20260110', ['#TRANS 1930 {} 0.00'])]), 'färre än två rader'],
    'date outside the year' => [fn () => sieImportFile([sieImportVoucher('A', 1, '20270110', ['#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00'])]), 'utanför räkenskapsåret'],
    'unreadable amount' => [fn () => sieImportFile([sieImportVoucher('A', 1, '20260110', ['#TRANS 1930 {} 100,00', '#TRANS 3001 {} -100.00'])]), 'går inte att läsa'],
    'another company' => [fn () => sieImportFile([], organisationNumber: '556123-4567'), 'Ladda upp filen i rätt företag'],
    'not type 4' => [fn () => sieImportFile([], type: '1'), 'SIE-typ 1'],
    'no fiscal year' => [fn () => "#FLAGGA 0\r\n#SIETYP 4\r\n", 'saknar räkenskapsår'],
    'year far away' => [fn () => sieImportFile([], year: '2020'), 'i tur och ordning'],
    'same number twice' => [fn () => sieImportFile([
        sieImportVoucher('A', 1, '20260110', ['#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00']),
        sieImportVoucher('A', 1, '20260111', ['#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00']),
    ]), 'samma verifikationsnummer'],
    'changed after it was made' => [fn () => str_replace('1250.00', '1350.00', (string) file_get_contents(SIE_IMPORT_FIXTURE)), 'Kontrollsumman'],
    'cut short' => [fn () => substr((string) file_get_contents(SIE_IMPORT_FIXTURE), 0, -40), 'ofullständig'],
]);

it('refuses a year that is closed, or overlaps with other dates', function () {
    FiscalYear::query()->whereDate('start_date', '2026-01-01')->update(['status' => 'CLOSED']);
    expect(implode(' ', analyseSie($this->owner, $this->company, sieImportFile([]))->blockers()))->toContain('är stängt');

    FiscalYear::query()->whereDate('start_date', '2026-01-01')->update(['status' => 'OPEN']);
    $overlapping = str_replace('#RAR 0 20260101 20261231', '#RAR 0 20260701 20270630', sieImportFile([]));
    expect(implode(' ', analyseSie($this->owner, $this->company, $overlapping)->blockers()))->toContain('överlappar');
});

it('refuses a locked period', function () {
    $this->company->forceFill(['locked_until_date' => '2026-03-31'])->save();

    $import = analyseSie($this->owner, $this->company, sieImportFile([sieImportVoucher('A', 1, '20260110', ['#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00'])]));

    expect(implode(' ', $import->blockers()))->toContain('är låst');
});

it('never reads the same file in twice', function () {
    importSieFile($this->owner, $this->company, (string) file_get_contents(SIE_IMPORT_FIXTURE));

    expect(analyseSie($this->owner, $this->company, (string) file_get_contents(SIE_IMPORT_FIXTURE))->blockers())
        ->toContain('Den här filen är redan inläst.');

    $sameVouchersOtherFile = str_replace('#GEN 20270115', '#GEN 20270116', (string) file_get_contents(SIE_IMPORT_FIXTURE));
    expect(implode(' ', analyseSie($this->owner, $this->company, $sameVouchersOtherFile)->blockers()))->toContain('redan inlästa');
});

it('adds vouchers after those already posted, with new numbers', function () {
    Bookkeeping::post($this->owner, $this->company, date: '2026-01-05');

    $import = importSieFile($this->owner, $this->company, sieImportFile([
        sieImportVoucher('A', 1, '20260110', ['#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00']),
    ]));

    expect($import->report['series'][0])->toMatchArray(['keep' => false, 'first' => 2])
        ->and(implode(' ', $import->report['warnings']))->toContain('redan 1 bokförda verifikationer')
        ->and($import->status)->toBe(SieImportStatus::Completed)
        ->and(ImportedVoucher::query()->sole()->originalReference())->toBe('A1')
        ->and(Voucher::query()->whereKey(ImportedVoucher::query()->value('voucher_id'))->sole()->reference())->toBe('A2')
        ->and(app(LedgerVerifier::class)->verify($this->company)['ok'])->toBeTrue();
});

it('rolls everything back when the result does not match the file', function () {
    $import = importSieFile($this->owner, $this->company, sieImportFile([
        '#KONTO 6999 "Särskilda avgifter"',
        '#UB 0 1930 999.00',
        sieImportVoucher('A', 1, '20250110', ['#TRANS 6999 {} 100.00', '#TRANS 1930 {} -100.00']),
    ], year: '2025'));

    expect($import->status)->toBe(SieImportStatus::Failed)
        ->and($import->error)->toContain('Avstämningen mot filen stämmer inte')
        ->and($import->result['differences'])->toEqual([['account' => 1930, 'kind' => 'UB', 'file' => 99_900, 'here' => -10_000]])
        ->and(Voucher::count())->toBe(0)
        ->and(FiscalYear::query()->whereDate('start_date', '2025-01-01')->exists())->toBeFalse()
        ->and(Account::query()->where('number', 6999)->exists())->toBeFalse()
        ->and(ImportedVoucher::count())->toBe(0);
});

it('creates the fiscal year before the first one', function () {
    $import = importSieFile($this->owner, $this->company, sieImportFile([
        sieImportVoucher('A', 1, '20250110', ['#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00']),
    ], year: '2025'));

    expect($import->status)->toBe(SieImportStatus::Completed)
        ->and($import->fiscalYear->label())->toBe('2025')
        ->and($import->report['fiscal_year']['create'])->toBeTrue()
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.created')->latest('id')->first()->payload['source'])->toBe('sie_import');
});

it('keeps the import record and row history unchangeable', function () {
    importSieFile($this->owner, $this->company, (string) file_get_contents(SIE_IMPORT_FIXTURE));

    $record = ImportedVoucher::query()->first();
    $entry = VoucherRowHistory::query()->first();

    expect(fn () => $record->update(['signature' => 'X']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);

    foreach (['update imported_vouchers set signature = ?' => ['X'], 'delete from voucher_row_history' => []] as $sql => $bindings) {
        $refused = false;

        try {
            DB::transaction(fn () => DB::statement($sql, $bindings));
        } catch (QueryException $exception) {
            $refused = str_contains($exception->getMessage(), 'cannot be changed or removed');
        }

        expect($refused)->toBeTrue();
    }
});

describe('in the browser', function () {
    it('uploads, shows the report and reads the file in with toasts', function () {
        $this->get(route('accounting.sieImports.index'))->assertOk()->assertSee('Ladda upp en fil');

        $response = $this->post(route('accounting.sieImports.store'), [
            'file' => sieUpload((string) file_get_contents(SIE_IMPORT_FIXTURE), 'SIE4 2026.se'),
        ]);

        $import = SieImport::query()->sole();
        $response->assertRedirect(route('accounting.sieImports.show', $import));
        expect(session('toasts')[0]['message'])->toBe('Filen är granskad. Läs igenom rapporten och läs sedan in den.');

        $this->get(route('accounting.sieImports.show', $import))->assertOk()
            ->assertSee('SIE4 2026.se')
            ->assertSee('Särskilda avgifter')
            ->assertSee('får nya nummer från 1');

        Livewire::test(SieImportReport::class, ['sieImport' => $import])
            ->assertSee('Ja, bokför 3 verifikationer')
            ->call('confirm')
            ->assertDispatched('toast', message: 'Filen är inläst: 3 verifikationer, och allt stämmer med filen.')
            ->assertSee('Filen är inläst')
            ->assertDontSee('Ja, bokför');

        $this->get(route('accounting.sieImports.index'))->assertSee('Inläst');
    });

    it('shows why a file cannot be read in, with an error toast', function () {
        $this->post(route('accounting.sieImports.store'), ['file' => sieUpload(sieImportFile([], type: '1'))]);

        $import = SieImport::query()->sole();
        expect(session('toasts')[0]['type'])->toBe('error');

        Livewire::test(SieImportReport::class, ['sieImport' => $import])
            ->assertSee('Filen kan inte läsas in som den är')
            ->assertSee('SIE-typ 1')
            ->assertDontSee('Ja, bokför');
    });

    it('asks for a file', function () {
        $this->post(route('accounting.sieImports.store'), [])->assertSessionHasErrors(['file' => 'Välj en SIE-fil att läsa in.']);
    });

    it('lets only those with import:sie read files in', function (string $role, bool $allowed) {
        $member = Bookkeeping::member($this->company, $role);
        $this->actingAs($member);

        $this->get(route('accounting.sieImports.index'))->assertStatus($allowed ? 200 : 403);
        $this->post(route('accounting.sieImports.store'), ['file' => sieUpload(sieImportFile([]))])->assertStatus($allowed ? 302 : 403);
    })->with([
        'admin' => ['admin', true],
        'accountant' => ['accountant', true],
        'bookkeeper' => ['bookkeeper', false],
        'approver' => ['approver', false],
        'auditor' => ['auditor', false],
    ]);

    it('answers 404 for another company\'s import', function () {
        $import = analyseSie($this->owner, $this->company, sieImportFile([]));

        [$stranger] = Bookkeeping::company();
        $this->actingAs($stranger);

        $this->get(route('accounting.sieImports.show', $import))->assertNotFound();
    });
});

it('offers to move a file uploaded in the wrong company to the user\'s company with that organisation number', function () {
    $this->company->forceFill(['org_number' => '556123-4567'])->save();
    $other = Company::factory()->create(['org_number' => '556334-3689', 'name' => 'Rätt Bolag AB', 'user_id' => $this->owner->id, 'personal_team' => false]);
    $other->users()->attach($this->owner, ['role' => 'owner']);
    app(InitialiseBookkeeping::class)($other, $this->owner, CarbonImmutable::parse('2026-06-15'));

    $import = analyseSie($this->owner, $this->company, (string) file_get_contents(SIE_IMPORT_FIXTURE));

    expect($import->blockers())->not->toBe([])
        ->and(array_map(fn ($company) => $company->id, MoveSieImportToCompany::candidates($this->owner, $import)))->toBe([$other->id]);

    Livewire::test(SieImportReport::class, ['sieImport' => $import])
        ->assertSee('Byt till Rätt Bolag AB och granska filen där')
        ->call('moveTo', $other->id)
        ->assertRedirect();

    $moved = SieImport::query()->withoutGlobalScopes()->where('company_id', $other->id)->latest('id')->firstOrFail();

    expect($this->owner->fresh()->current_team_id)->toBe($other->id)
        ->and($moved->blockers())->toBe([])
        ->and($moved->status)->toBe(SieImportStatus::Analysed);
});

it('offers to create the company named in the file when the user has none with that number', function () {
    $this->company->forceFill(['org_number' => '556123-4567'])->save();
    $import = analyseSie($this->owner, $this->company, (string) file_get_contents(SIE_IMPORT_FIXTURE));

    Livewire::test(SieImportReport::class, ['sieImport' => $import])
        ->assertSee('Skapa företaget med uppgifterna från filen')
        ->call('createCompanyFromFile')
        ->assertRedirect(route('teams.create'));

    Livewire::test(CreateCompanyForm::class)
        ->assertSet('org_number', '556334-3689')
        ->assertSet('name', 'Exempelbolaget i Malmö AB');
});

it('reads in a short fiscal year: the law sets no minimum length (BFL 3 kap. 3 §)', function () {
    $short = str_replace('#RAR 0 20260101 20261231', '#RAR 0 20251101 20251231', sieImportFile([
        sieImportVoucher('A', 1, '20251110', ['#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00']),
    ]));

    $import = analyseSie($this->owner, $this->company, $short);

    expect($import->blockers())->toBe([])
        ->and($import->report['fiscal_year'])->toMatchArray(['create' => true]);

    app(ConfirmSieImport::class)($this->owner, $import);

    expect($import->fresh()->status)->toBe(SieImportStatus::Completed)
        ->and(FiscalYear::query()->whereDate('start_date', '2025-11-01')->sole()->end_date->toDateString())->toBe('2025-12-31');
});

it('reads in a sole trader\'s broken year as it was kept, with a warning', function () {
    $this->company->forceFill(['legal_form' => 'EF', 'reporting_framework' => 'K1'])->save();

    $calendar = str_replace('#RAR 0 20260101 20261231', '#RAR 0 20250501 20251231', sieImportFile([]));
    $import = analyseSie($this->owner, $this->company, $calendar);

    expect($import->blockers())->toBe([])
        ->and(implode(' ', $import->report['warnings']))->not->toContain('slutar inte den 31 december');

    $broken = str_replace('#RAR 0 20260101 20261231', '#RAR 0 20270101 20270630', sieImportFile([]));
    $import = analyseSie($this->owner, $this->company, $broken);

    expect($import->blockers())->toBe([])
        ->and(implode(' ', $import->report['warnings']))->toContain('slutar inte den 31 december');
});
