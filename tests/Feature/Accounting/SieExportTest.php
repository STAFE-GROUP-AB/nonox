<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\ExportSie;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Sie\SieAmount;
use App\Support\Sie\SieChecksum;
use App\Support\Sie\SieReader;
use App\Support\Sie\SieRecord;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

const SIE_EXPORT_FIXTURE = __DIR__.'/../../Fixtures/sie/export-2026.se';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->owner->forceFill(['name' => 'Anna Andersson'])->save();
    $this->company->forceFill([
        'name' => 'Exempelbolaget',
        'legal_name' => 'Exempelbolaget i Malmö AB',
        'org_number' => '5563343689',
        'legal_form' => 'AB',
        'address_line1' => 'Storgatan 1',
        'postal_code' => '211 20',
        'city' => 'Malmö',
        'phone' => '040-123 45',
        'bas_version' => 'BAS 2026',
    ])->save();
});

/**
 * A small but complete bookkeeping: a previous year with share capital, its
 * closing balance carried in as opening balance, a sale with VAT, a purchase
 * that is corrected, a voucher in another series and a draft that must stay
 * out of the file.
 */
function bookkeepingToExport(User $owner, $company): FiscalYear
{
    $previous = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    Bookkeeping::post($owner, $company, 5_000_000, 1930, 2081, '2025-02-01');

    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => accountId(1930), 'amount_ore' => 5_000_000]);
    OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => accountId(2081), 'amount_ore' => -5_000_000]);

    app(PostVoucher::class)($owner, app(SaveDraftVoucher::class)($owner, $company, [
        'voucher_date' => '2026-03-15',
        'description' => 'Faktura 1001 – Kund i Öresund AB',
        'series' => 'A',
        'rows' => [
            ['account' => 1930, 'debit_ore' => 125_000],
            ['account' => 3001, 'credit_ore' => 100_000, 'description' => 'Konsulttjänst "Projekt Ö"'],
            ['account' => 2611, 'credit_ore' => 25_000],
        ],
    ]));

    $purchase = Bookkeeping::post($owner, $company, 50_000, 6110, 1930, '2026-04-02');
    Bookkeeping::post($owner, $company, 20_000, 1930, 3001, '2026-05-01', 'B');
    app(CorrectVoucher::class)($owner, $purchase, 'Fel konto');
    Bookkeeping::draft($owner, $company, 99_900, date: '2026-06-01');

    expect($previous->id)->not->toBe($year->id);

    return $year;
}

function accountId(int $number): int
{
    return (int) DB::table('accounts')->where('company_id', CurrentCompany::id())->where('number', $number)->value('id');
}

function exportSie(User $user, FiscalYear $year): string
{
    $stream = fopen('php://memory', 'w+b');
    app(ExportSie::class)($user, $year, $stream);
    rewind($stream);

    return (string) stream_get_contents($stream);
}

it('writes the fiscal year exactly as the golden file', function () {
    $file = exportSie($this->owner, bookkeepingToExport($this->owner, $this->company));

    if (getenv('UPDATE_SIE_FIXTURES')) {
        file_put_contents(SIE_EXPORT_FIXTURE, $file);
    }

    expect($file)->toBe(file_get_contents(SIE_EXPORT_FIXTURE));
});

it('writes a file that reads back with a valid control sum and the same ledger', function () {
    $year = bookkeepingToExport($this->owner, $this->company);
    $path = tempnam(sys_get_temp_dir(), 'sie');
    file_put_contents($path, exportSie($this->owner, $year));

    $reader = new SieReader($path);
    $records = iterator_to_array($reader->records(), false);
    $byLabel = fn (string $label) => array_values(array_filter($records, fn (SieRecord $record) => $record->label === $label));
    $amounts = fn (string $label, string $year) => collect($byLabel($label))
        ->filter(fn (SieRecord $record) => $record->field(0) === $year)
        ->mapWithKeys(fn (SieRecord $record) => [(int) $record->field(1) => SieAmount::toOre((string) $record->field(2))])
        ->all();

    expect($reader->checksum())->toBe(SieChecksum::Valid)
        ->and($reader->problems())->toBe([])
        ->and(count($byLabel('#KONTO')))->toBe(DB::table('accounts')->where('company_id', $this->company->id)->count())
        ->and($amounts('#IB', '0'))->toBe([1930 => 5_000_000, 2081 => -5_000_000])
        ->and($amounts('#UB', '0'))->toBe([1930 => 5_145_000, 2081 => -5_000_000, 2611 => -25_000])
        ->and($amounts('#RES', '0'))->toBe([3001 => -120_000])
        ->and($amounts('#UB', '-1'))->toBe([1930 => 5_000_000, 2081 => -5_000_000])
        ->and($amounts('#IB', '-1'))->toBe([]);

    $vouchers = $byLabel('#VER');

    expect(array_map(fn (SieRecord $voucher) => $voucher->field(0).$voucher->field(1), $vouchers))->toBe(['A1', 'A2', 'A3', 'B1']);

    foreach ($vouchers as $voucher) {
        $sum = array_sum(array_map(fn (SieRecord $row) => SieAmount::toOre((string) $row->field(2)), $voucher->rows));

        expect($sum)->toBe(0)
            ->and($voucher->field(5))->toBe('Anna Andersson');
    }

    expect($vouchers[0]->field(3))->toBe('Faktura 1001 - Kund i Öresund AB')
        ->and($vouchers[0]->rows[1]->field(4))->toBe('Konsulttjänst "Projekt Ö"');
});

it('leaves drafts and other years out', function () {
    $year = bookkeepingToExport($this->owner, $this->company);

    expect(exportSie($this->owner, $year))
        ->not->toContain('999.00')
        ->not->toContain('20250201');
});

it('downloads the file from the fiscal years page', function () {
    $year = bookkeepingToExport($this->owner, $this->company);

    $this->get(route('accounting.fiscalYears'))
        ->assertOk()
        ->assertSee('Ladda ner SIE-fil')
        ->assertSee(route('accounting.fiscalYears.sie', $year), escape: false);

    $response = $this->get(route('accounting.fiscalYears.sie', $year))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=SIE4_5563343689_2026.se')
        ->and($response->headers->get('Content-Type'))->toBe('text/plain; charset=IBM437')
        ->and($response->streamedContent())->toStartWith("#FLAGGA 0\r\n#KSUMMA\r\n");

    expect(AuditLog::forCompany($this->company)->where('action', 'sie.exported')->sole()->payload)
        ->toEqual(['fiscal_year' => '2026', 'vouchers' => 4]);
});

it('lets only roles with export rights download', function (string $role, bool $allowed) {
    $year = FiscalYear::query()->sole();
    $member = Bookkeeping::member($this->company, $role);

    $this->actingAs($member)
        ->get(route('accounting.fiscalYears.sie', $year))
        ->assertStatus($allowed ? 200 : 403);

    $page = $this->get(route('accounting.fiscalYears'))->assertOk();
    $allowed ? $page->assertSee('Ladda ner SIE-fil') : $page->assertDontSee('Ladda ner SIE-fil');
})->with([
    ['accountant', true],
    ['auditor', true],
    ['bookkeeper', false],
    ['approver', false],
]);

it('answers 404 for another company\'s fiscal year', function () {
    [$stranger, $otherCompany] = Bookkeeping::company();
    $otherYear = CurrentCompany::run($otherCompany, fn () => FiscalYear::query()->sole());

    $this->get(route('accounting.fiscalYears.sie', $otherYear->id))->assertNotFound();
});
