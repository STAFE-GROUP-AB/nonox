<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Migration\Import\Actions\ConfirmRegisterImport;
use App\Migration\Import\RegisterFileReader;
use App\Migration\Import\RegisterImportRunner;
use App\Migration\Import\RegisterKind;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\RegisterImport;
use App\Models\RegisterImportPreset;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * ADR-076, S3: the customer import in two steps, with synthetic files.
 */
beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
});

function importedCustomers(object $test): Collection
{
    return CurrentCompany::run($test->company, fn () => Customer::query()->orderBy('number')->get());
}

it('reads a file in two steps and changes nothing until it is confirmed', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');

    expect($import->status)->toBe(RegisterImport::MAPPING)
        ->and($import->options['mapping'])->toMatchArray(['name' => 'Name', 'external_ref' => 'CustomerNumber', 'org_number' => 'Corp.ID no.']);

    $import = RegisterImports::analyse($this->owner, $import);

    expect($import->status)->toBe(RegisterImport::ANALYSED)
        ->and($import->blockers())->toBe([])
        ->and($import->report['counts'])->toMatchArray(['read' => 4, 'create' => 3, 'error' => 1])
        ->and(importedCustomers($this))->toHaveCount(0);
});

it('creates customers through SaveCustomer, with the previous program\'s number kept as external_ref', function () {
    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');
    $customers = importedCustomers($this);

    expect($import->status)->toBe(RegisterImport::COMPLETED)
        ->and($import->result)->toMatchArray(['created' => 3, 'error_count' => 1])
        ->and($customers)->toHaveCount(3)
        ->and($customers->pluck('external_ref')->all())->toBe(['1001', '1002', '1003'])
        ->and($customers[0]->org_number)->toBe('556677-8899')
        ->and($customers[0]->payment_terms_days)->toBe(30)
        // The private person's number is a personal number, not an organisation number.
        ->and($customers[1]->personal_number)->toBe('8112189876')
        ->and($customers[1]->org_number)->toBeNull()
        ->and($customers[1]->payment_terms_days)->toBe(0)
        ->and($customers[2]->is_active)->toBeFalse()
        // NONOX numbers come from the company's own series.
        ->and($customers->pluck('number')->all())->toBe([1, 2, 3]);
});

it('keeps names and numbers out of the import audit entries', function () {
    RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');

    $entries = AuditLog::query()->where('company_id', $this->company->id)->whereIn('action', ['register.uploaded', 'register.analysed', 'register.imported'])->get();
    $json = $entries->toJson();

    expect($entries->pluck('action')->unique()->sort()->values()->all())->toBe(['register.analysed', 'register.imported', 'register.uploaded'])
        ->and($json)->not->toContain('Exempelbolaget')
        ->and($json)->not->toContain('8112189876')
        ->and($json)->not->toContain('556677');
});

it('refuses the same file twice but lets a corrected file add only what is new', function () {
    RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');

    expect(fn () => RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris'))
        ->toThrow(ValidationException::class);

    $corrected = file_get_contents(base_path('tests/Fixtures/import/kunder-spiris-lik.csv'))."True,1005,Ny Kund AB,False,559900-1129,Nygatan 1,,111 11,Nystad,SE,,,ny@example.test,40,SEK\n";
    // 559900-1129 already belongs to customer 1003: matched on the number and skipped.
    $corrected .= "True,1006,Helt Ny AB,False,556000-1116,Gatan 2,,222 22,Stad,SE,,,helt@example.test,40,SEK\n";
    $second = RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($corrected), 'spiris');

    expect($second->result)->toMatchArray(['created' => 1])
        ->and(importedCustomers($this))->toHaveCount(4)
        ->and(importedCustomers($this)->last()->name)->toBe('Helt Ny AB');
});

it('skips records that exist, matched on number, then external_ref, then name and postal code', function () {
    CurrentCompany::run($this->company, function () {
        $save = app(SaveCustomer::class);
        $save($this->owner, $this->company, ['name' => 'Redan Här AB', 'org_number' => '556677-8899', 'payment_terms_days' => 30]);
        $save($this->owner, $this->company, ['name' => 'Via Ref', 'external_ref' => '1002', 'payment_terms_days' => 30]);
        $save($this->owner, $this->company, ['name' => 'Syntetiska Syd HB', 'postal_code' => '333 44', 'payment_terms_days' => 30]);
    });

    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');

    expect($import->result)->toMatchArray(['created' => 0, 'skipped' => 3])
        ->and(importedCustomers($this))->toHaveCount(3);
});

it('fills only empty fields of an existing customer when asked, never overwrites', function () {
    $existing = CurrentCompany::run($this->company, fn () => app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Eget Namn', 'org_number' => '556677-8899', 'city' => 'Egen Stad', 'payment_terms_days' => 14,
    ]));

    $skip = RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');
    expect(CurrentCompany::run($this->company, fn () => $existing->fresh()->email))->toBeNull()
        ->and($skip->result['filled'])->toBe(0);

    $csv = file_get_contents(base_path('tests/Fixtures/import/kunder-spiris-lik.csv'))."\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($csv), 'spiris');
    $import = RegisterImports::analyse($this->owner, $import, ['fill_empty' => true]);
    expect($import->report['counts']['fill'])->toBe(1);
    app(ConfirmRegisterImport::class)($this->owner, $import);

    $fresh = CurrentCompany::run($this->company, fn () => $existing->fresh());
    expect($fresh->email)->toBe('norr@example.test')
        ->and($fresh->city)->toBe('Egen Stad')
        ->and($fresh->name)->toBe('Eget Namn')
        ->and($fresh->payment_terms_days)->toBe(14)
        ->and($fresh->external_ref)->toBe('1001');
});

it('reports the later row of a duplicate in the file and skips it', function () {
    $csv = "Namn,Organisationsnummer\nA AB,556677-8899\nB AB,556677-8899\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($csv));
    $import = RegisterImports::analyse($this->owner, $import);

    expect($import->report['counts'])->toMatchArray(['create' => 1, 'duplicate' => 1])
        ->and(collect($import->report['rows'])->firstWhere('line', 3)['status'])->toBe('duplicate');
});

it('blocks the confirmation when a required field is unmapped or a column is used twice', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');

    $noName = RegisterImports::analyse($this->owner, $import, ['mapping' => ['external_ref' => 'CustomerNumber']]);
    expect($noName->canBeConfirmed())->toBeFalse()
        ->and($noName->blockers()[0])->toContain('Namn');

    $twice = RegisterImports::analyse($this->owner, $import, ['mapping' => ['name' => 'Name', 'city' => 'Name']]);
    expect($twice->canBeConfirmed())->toBeFalse()
        ->and(implode(' ', $twice->blockers()))->toContain('mer än ett fält');

    $missing = RegisterImports::analyse($this->owner, $import, ['mapping' => ['name' => 'Finns inte']]);
    expect(implode(' ', $missing->blockers()))->toContain('finns inte i filen');
});

it('validates numbers with Luhn, VAT, e-mail and country, masking personal numbers in the report', function () {
    $csv = "Namn,Org,Moms,Epost,Land\nOk AB,556677-8899,SE556677889901,ok@example.test,SE\nFel Org,556677-8890,,,SE\nFel Moms,559900-1129,123,,SE\nFel Mail,556000-1116,,inte-mail,SE\nFel Land,5566778899,,,Sverige\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($csv));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => ['name' => 'Namn', 'org_number' => 'Org', 'vat_number' => 'Moms', 'email' => 'Epost', 'country' => 'Land']]);

    expect($import->report['counts'])->toMatchArray(['read' => 5, 'create' => 1, 'error' => 4]);

    $private = "Namn,Pnr,Privat\nKalle,811218-9876,ja\nFel,811218-9877,ja\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($private, 'p.csv'));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => ['name' => 'Namn', 'org_number' => 'Pnr', 'is_private' => 'Privat']]);

    expect(json_encode($import->report))->not->toContain('8112189876')->not->toContain('811218-9876')
        ->and($import->report['rows'][0]['number'])->toEndWith('9876');
});

it('lists unreadable coded values and reads them once the user has mapped them', function () {
    $csv = "Namn,Villkor\nA AB,netto30\nB AB,kontant\nC AB,20\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($csv));
    $mapping = ['name' => 'Namn', 'payment_terms_days' => 'Villkor'];

    $first = RegisterImports::analyse($this->owner, $import, ['mapping' => $mapping]);
    expect($first->report['counts']['error'])->toBe(2)
        ->and(array_values($first->report['unmapped']['payment_terms_days']))->toBe(['netto30', 'kontant']);

    $second = RegisterImports::analyse($this->owner, $first, ['value_maps' => ['payment_terms_days' => ['netto30' => '30', 'kontant' => '0']]]);
    expect($second->report['counts'])->toMatchArray(['create' => 3, 'error' => 0]);
});

it('reads a Windows-1252 file with the user\'s own mapping and saves it as a company preset', function () {
    $mapping = ['name' => 'Företag', 'external_ref' => 'Kundnr', 'org_number' => 'Orgnr', 'city' => 'Ort', 'payment_terms_days' => 'Villkor'];
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-semikolon-windows1252.csv'));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => $mapping, 'preset_name' => 'Mitt format']);

    app(ConfirmRegisterImport::class)($this->owner, $import);

    expect(importedCustomers($this)->pluck('name')->all())->toBe(['Åkerströms Åkeri AB', 'Björklunds Bygg HB'])
        ->and(importedCustomers($this)->pluck('city')->all())->toBe(['Örebro', 'Växjö'])
        ->and(importedCustomers($this)[1]->payment_terms_days)->toBe(20);

    $preset = CurrentCompany::run($this->company, fn () => RegisterImportPreset::query()->sole());
    $next = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents(file_get_contents(base_path('tests/Fixtures/import/kunder-semikolon-windows1252.csv')).";\n"), "company:{$preset->id}");

    expect($next->options['mapping'])->toEqual($mapping);
});

it('reads xlsx files and never evaluates formula cells', function () {
    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-egen.xlsx'), 'nonox', [
        'mapping' => ['name' => 'Namn', 'org_number' => 'Organisationsnummer', 'email' => 'E-post'],
    ]);

    expect($import->result['created'])->toBe(2)
        ->and(importedCustomers($this)->pluck('name')->all())->toBe(['Xlsx Kund AB', '=1+1']);
});

it('stops at a failing chunk, keeps the earlier chunks and can be rerun safely', function () {
    $csv = "Namn,Org\nA AB,556677-8899\nB AB,559900-1129\nC AB,556000-1116\nD AB,556700-1234\n";
    RegisterImportRunner::$chunkSize = 2;

    $calls = 0;
    app()->bind(SaveCustomer::class, function () use (&$calls) {
        return new class($calls) extends SaveCustomer
        {
            public function __construct(private int &$calls) {}

            public function __invoke(User $user, Company $company, array $input, ?Customer $customer = null): Customer
            {
                if (++$this->calls === 3) {
                    throw new RuntimeException('boom');
                }

                return parent::__invoke($user, $company, $input, $customer);
            }
        };
    });

    $mapping = ['name' => 'Namn', 'org_number' => 'Org'];
    $failed = RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($csv), 'nonox', ['mapping' => $mapping]);

    expect($failed->status)->toBe(RegisterImport::FAILED)
        ->and($failed->error)->not->toBeNull()
        ->and(importedCustomers($this))->toHaveCount(2);

    app()->forgetInstance(SaveCustomer::class);
    app()->offsetUnset(SaveCustomer::class);
    RegisterImportRunner::$chunkSize = RegisterImportRunner::CHUNK;

    $again = RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($csv.' '), 'nonox', ['mapping' => $mapping]);

    expect($again->status)->toBe(RegisterImport::COMPLETED)
        ->and($again->result)->toMatchArray(['created' => 2, 'skipped' => 2])
        ->and(importedCustomers($this))->toHaveCount(4);
});

it('runs a fixed number of queries whatever the length of the file', function () {
    $queries = function (int $rows): int {
        $csv = "Namn,Org,Ort\n";

        for ($i = 0; $i < $rows; $i++) {
            $csv .= "Kund {$i} AB,,Ort {$i}\n";
        }

        $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($csv.str_repeat('', 0), "k{$rows}.csv"));
        DB::flushQueryLog();
        DB::enableQueryLog();
        RegisterImports::analyse($this->owner, $import, ['mapping' => ['name' => 'Namn', 'city' => 'Ort']]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $queries(5);

    expect($queries(60))->toBe($queries(3));
});

it('refuses a file with more rows than the limit and says to split it', function () {
    $handle = fopen('php://temp', 'w+');
    fwrite($handle, "Namn\n");

    for ($i = 0; $i <= RegisterFileReader::MAX_ROWS; $i++) {
        fwrite($handle, "K{$i}\n");
    }

    rewind($handle);
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents((string) stream_get_contents($handle), 'stor.csv'));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => ['name' => 'Namn']]);

    expect($import->canBeConfirmed())->toBeFalse()
        ->and(implode(' ', $import->blockers()))->toContain('fler än 100 000 rader')
        ->and(importedCustomers($this))->toHaveCount(0);
});

it('rejects a file that is not readable as a table', function () {
    expect(fn () => RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents("PK\x03\x04trasig", 'trasig.xlsx')))
        ->toThrow(ValidationException::class);
});
