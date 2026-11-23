<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Models\ArchivePackage;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Support\Archive\ArchivePackageBuilder;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['bankgiro' => '5050-1055', 'vat_number' => 'SE556334368901', 'f_skatt' => true])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->orderBy('start_date')->firstOrFail();
});

/** @return array<string, string> */
function priceArchiveFiles(ArchivePackage $package): array
{
    $zipPath = tempnam(sys_get_temp_dir(), 'arkiv');
    file_put_contents($zipPath, Storage::disk('local')->get((string) $package->file_path));
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $files[substr($name, strpos($name, '/') + 1)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($zipPath);

    return $files;
}

function priceInvoice(string $date, string $description): Invoice
{
    $customer = app(SaveCustomer::class)(test()->owner, test()->company, ['name' => 'Kund '.$description, 'org_number' => '5563343689', 'email' => 'k@kund.test', 'payment_terms_days' => 20]);
    $draft = app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id,
        'invoice_date' => $date,
        'rows' => [
            ['description' => $description, 'quantity' => '12', 'price' => '119,00', 'vat_rate' => 1200, 'price_source' => 'MANUAL'],
            ['description' => $description.' extra', 'quantity' => '1', 'price' => '10,50', 'vat_rate' => 1200, 'price_source' => 'MANUAL'],
        ],
    ]);
    InvoiceRow::query()->where('invoice_id', $draft->id)->update(['price_source' => 'MANUAL', 'price_explanation' => json_encode(['rule' => 'manual', 'minor' => 11900])]);

    return app(IssueInvoice::class)(test()->owner, $draft);
}

it('puts how each invoice row was priced into the package, credit invoices too', function () {
    $invoice = priceInvoice('2026-03-02', 'Lunchbuffé');
    $credit = app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice->refresh()));

    $files = priceArchiveFiles(app(CreateArchivePackage::class)($this->owner, $this->year));
    $csv = $files['kundfakturor/Prisuppgifter.csv'];
    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($lines)->toHaveCount(5)
        ->and($lines[0])->toContain('Fakturanummer;Typ;Fakturadatum;Rad;Artikelnummer;Beskrivning;Antal;"À-pris, kronor";"Prisets ursprung"')
        ->and($lines[1])->toContain("{$invoice->number};Faktura;2026-03-02;1;;Lunchbuffé;12.000;119,00;MANUAL;")
        ->and($lines[1])->toContain(';119,00;MANUAL;')
        ->and($lines[0])->toEndWith('Valuta;"À-pris i fakturans valuta"')
        ->and($lines[1])->toEndWith(';SEK;')
        ->and($lines[1])->toContain('minor')
        ->and($lines[2])->toContain(';10,50;MANUAL;')
        ->and($csv)->toContain("{$credit->number};Kreditfaktura")
        ->and($files['LASMIG.txt'])->toContain('Prisuppgifter.csv')
        ->and($files['SHA256SUMS.txt'])->toContain(hash('sha256', $csv));

    $manifest = json_decode($files['manifest.json'], true);
    expect(json_encode($manifest['files']))->toContain('Prisuppgifter.csv');
});

it('leaves out drafts and other companies from the price file', function () {
    priceInvoice('2026-03-02', 'Egen rad');
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Utkast AB', 'org_number' => '5563343689', 'email' => 'u@kund.test', 'payment_terms_days' => 20]);
    app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $customer->id, 'invoice_date' => '2026-03-03', 'rows' => [['description' => 'Utkastrad', 'quantity' => '1', 'price' => '5,00', 'vat_rate' => 1200]]]);

    [$otherOwner, $other] = Bookkeeping::company();
    $other->forceFill(['bankgiro' => '5050-1055', 'vat_number' => 'SE556334368901', 'f_skatt' => true])->save();
    CurrentCompany::set($other);
    $this->actingAs($otherOwner);
    $otherCustomer = app(SaveCustomer::class)($otherOwner, $other, ['name' => 'Annan kund', 'org_number' => '5563343689', 'email' => 'a@kund.test', 'payment_terms_days' => 20]);
    app(IssueInvoice::class)($otherOwner, app(SaveDraftInvoice::class)($otherOwner, $other, ['customer_id' => $otherCustomer->id, 'invoice_date' => '2026-03-02', 'rows' => [['description' => 'Annat företags rad', 'quantity' => '1', 'price' => '77,00', 'vat_rate' => 1200, 'price_source' => 'MANUAL']]]));

    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $csv = priceArchiveFiles(app(CreateArchivePackage::class)($this->owner, $this->year))['kundfakturor/Prisuppgifter.csv'];

    expect($csv)->toContain('Egen rad')
        ->not->toContain('Utkastrad')
        ->not->toContain('Annat företags rad');
});

it('writes no price file when the year has no issued invoice rows', function () {
    expect(priceArchiveFiles(app(CreateArchivePackage::class)($this->owner, $this->year)))->not->toHaveKey('kundfakturor/Prisuppgifter.csv');
});

it('reads the price rows with a fixed number of queries however many invoices there are', function () {
    $count = function (): int {
        $builder = app(ArchivePackageBuilder::class);
        $root = new ReflectionProperty($builder, 'root');
        $dir = sys_get_temp_dir().'/arkiv-pris-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $root->setValue($builder, $dir);
        (new ReflectionProperty($builder, 'files'))->setValue($builder, []);

        DB::flushQueryLog();
        DB::enableQueryLog();
        (new ReflectionMethod($builder, 'priceInformation'))->invoke($builder, $this->year);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    priceInvoice('2026-03-02', 'Ett');
    $one = $count();

    foreach (range(1, 6) as $i) {
        priceInvoice('2026-03-0'.($i + 2), "Rad {$i}");
    }

    expect($count())->toBe($one);
});
