<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Invoicing\SaveCustomer;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Support\Portability\DumpChainVerifier;
use App\Support\Portability\JsonDumpWriter;
use App\Support\Portability\SchemaValidator;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->dir = sys_get_temp_dir().'/nonox-dump-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/** @return array<string, mixed> */
function readDump(string $dir): array
{
    return json_decode((string) file_get_contents($dir.'/dump.json'), true, 512, JSON_THROW_ON_ERROR);
}

function pdfUpload(string $name, string $body = 'one'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$body}\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
}

it('writes a dump that validates against the published schema', function () {
    Bookkeeping::post($this->owner, $this->company, 125_00, 1930, 3001, '2026-03-02');
    app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'payment_terms_days' => 30]);

    $result = app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $dump = readDump($this->dir);

    expect(SchemaValidator::forDump()->validate($dump))->toBe([])
        ->and($dump['format'])->toBe('nonox-dump')
        ->and($dump['schema_version'])->toBe(JsonDumpWriter::SCHEMA_VERSION)
        ->and($dump['counts']['vouchers'])->toBe(1)
        ->and($dump['counts']['customers'])->toBe(1)
        ->and($result['counts'])->toBe($dump['counts']);
});

it('rejects a broken dump', function () {
    $dump = [
        'format' => 'something-else',
        'vouchers' => [['id' => 'x', 'rows' => [['debit_ore' => -5]]]],
    ];

    expect(SchemaValidator::forDump()->validate($dump))->not->toBe([]);
});

it('keeps number, hash and previous hash of posted vouchers and reproduces them from the dump alone', function () {
    $first = Bookkeeping::post($this->owner, $this->company, 100_00, 1930, 3001, '2026-03-02');
    $second = Bookkeeping::post($this->owner, $this->company, 250_50, 1930, 3001, '2026-03-03');
    Bookkeeping::draft($this->owner, $this->company, 10_00, 1930, 3001, '2026-03-04');

    app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $dump = readDump($this->dir);
    $byId = collect($dump['vouchers'])->keyBy('id');

    expect($byId[$second->id]['number'])->toBe($second->number)
        ->and($byId[$second->id]['hash'])->toBe($second->fresh()->hash)
        ->and($byId[$second->id]['previous_hash'])->toBe($first->fresh()->hash)
        ->and($byId[$second->id]['rows'])->toHaveCount(2)
        ->and($byId[$second->id]['rows'][0]['debit_ore'])->toBe(250_50)
        ->and($dump['fiscal_years'][0]['ledger_head_hash'])->toBe($second->fresh()->hash)
        ->and(collect($dump['vouchers'])->where('status', 'DRAFT'))->toHaveCount(1)
        ->and(DumpChainVerifier::verify($dump))->toBe([]);
});

it('detects a changed amount through the chain verifier', function () {
    Bookkeeping::post($this->owner, $this->company, 100_00, 1930, 3001, '2026-03-02');

    app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $dump = readDump($this->dir);
    $dump['vouchers'][0]['rows'][0]['debit_ore'] += 1;

    expect(DumpChainVerifier::verify($dump))->not->toBe([]);
});

it('never contains another company\'s data', function () {
    Bookkeeping::post($this->owner, $this->company, 100_00, 1930, 3001, '2026-03-02');
    app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Vår kund AB', 'payment_terms_days' => 30]);

    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::set($other);
    $this->actingAs($otherOwner);
    $foreign = Bookkeeping::post($otherOwner, $other, 999_99, 1930, 3001, '2026-03-05');
    $foreignAttachment = Bookkeeping::draft($otherOwner, $other, 5_00, 1930, 3001, '2026-03-06');
    app(AttachToVoucher::class)($otherOwner, $foreignAttachment, pdfUpload('hemlig.pdf', 'foreign secret'));
    app(SaveCustomer::class)($otherOwner, $other, ['name' => 'Främmande kund AB', 'payment_terms_days' => 30]);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $raw = (string) file_get_contents($this->dir.'/dump.json');
    $dump = readDump($this->dir);

    expect($raw)->not->toContain('Främmande kund AB')
        ->and($raw)->not->toContain($foreign->id)
        ->and($raw)->not->toContain('hemlig.pdf')
        ->and($dump['counts']['vouchers'])->toBe(1)
        ->and($dump['counts']['customers'])->toBe(1)
        ->and($dump['company']['source_id'])->toBe($this->company->id)
        ->and(File::exists($this->dir.'/attachments') ? File::files($this->dir.'/attachments') : [])->toBe([]);
});

it('stores each attachment once by SHA-256 and verifies the content', function () {
    $a = Bookkeeping::draft($this->owner, $this->company, 49_00, 5410, 1930, '2026-03-05');
    $b = Bookkeeping::draft($this->owner, $this->company, 59_00, 5410, 1930, '2026-03-06');
    app(AttachToVoucher::class)($this->owner, $a, pdfUpload('a.pdf', 'same'));
    app(AttachToVoucher::class)($this->owner, $b, pdfUpload('b.pdf', 'same'));
    app(AttachToVoucher::class)($this->owner, $b, pdfUpload('c.pdf', 'different'));

    $result = app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $dump = readDump($this->dir);
    $attachments = collect($dump['vouchers'])->pluck('attachments')->flatten(1);

    expect($attachments)->toHaveCount(3)
        ->and(File::files($this->dir.'/attachments'))->toHaveCount(2)
        ->and($result['warnings'])->toBe([]);

    foreach ($attachments as $attachment) {
        expect($attachment['file'])->toBe('attachments/'.$attachment['sha256'])
            ->and(hash_file('sha256', $this->dir.'/'.$attachment['file']))->toBe($attachment['sha256']);
    }
});

it('reports a missing or damaged attachment file instead of hiding it', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, 49_00, 5410, 1930, '2026-03-05');
    $good = app(AttachToVoucher::class)($this->owner, $draft, pdfUpload('ok.pdf', 'good'));
    $lost = app(AttachToVoucher::class)($this->owner, $draft, pdfUpload('lost.pdf', 'lost'));
    $broken = app(AttachToVoucher::class)($this->owner, $draft, pdfUpload('broken.pdf', 'broken'));
    Storage::disk('local')->delete($lost->file_path);
    Storage::disk('local')->put($broken->file_path, 'tampered');

    $result = app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $files = collect(readDump($this->dir)['vouchers'][0]['attachments'])->keyBy('original_name');

    expect($result['warnings'])->toHaveCount(2)
        ->and($files['ok.pdf']['file'])->not->toBeNull()
        ->and($files['lost.pdf']['file'])->toBeNull()
        ->and($files['broken.pdf']['file'])->toBeNull()
        ->and(File::files($this->dir.'/attachments'))->toHaveCount(1);
});

it('leaves personal numbers out unless asked, and then writes them decrypted', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Privat Person', 'personal_number' => '19121212-1212', 'payment_terms_days' => 30]);
    expect($customer->personal_number)->toBe('19121212-1212');

    app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $plain = (string) file_get_contents($this->dir.'/dump.json');
    expect($plain)->not->toContain('1212')
        ->and(readDump($this->dir)['includes_personal_numbers'])->toBeFalse();

    File::deleteDirectory($this->dir);
    app(JsonDumpWriter::class)->write($this->company, $this->dir, includePersonalNumbers: true);
    $dump = readDump($this->dir);

    expect($dump['includes_personal_numbers'])->toBeTrue()
        ->and($dump['customers'][0]['personal_number'])->toBe('19121212-1212')
        ->and(SchemaValidator::forDump()->validate($dump))->toBe([]);
});

it('writes number counters and fiscal years so a restore can continue numbering', function () {
    Bookkeeping::post($this->owner, $this->company, 100_00, 1930, 3001, '2026-03-02');
    Bookkeeping::post($this->owner, $this->company, 100_00, 1930, 3001, '2026-03-03');

    app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $dump = readDump($this->dir);
    $year = FiscalYear::query()->firstOrFail();

    expect($dump['fiscal_years'][0]['id'])->toBe($year->id)
        ->and($dump['voucher_number_counters'])->not->toBeEmpty()
        ->and(collect($dump['voucher_number_counters'])->max('next_number'))->toBe(3);
});

it('exports every column of the core tables or names it as excluded', function () {
    foreach (JsonDumpWriter::coverage() as $table => $columns) {
        $actual = DB::getSchemaBuilder()->getColumnListing($table);
        $known = array_merge($columns['exported'], $columns['excluded']);

        expect(array_values(array_diff($actual, $known)))->toBe([], "{$table} has columns the dump neither exports nor excludes")
            ->and(array_values(array_diff($known, $actual)))->toBe([], "{$table} lists columns that no longer exist");
    }
});

it('uses a constant number of queries for 10 and for 100 vouchers', function () {
    $count = function (int $vouchers): int {
        for ($i = 0; $i < $vouchers; $i++) {
            $draft = Bookkeeping::draft($this->owner, $this->company, 100_00 + $i, 1930, 3001, '2026-03-02');
            app(AttachToVoucher::class)($this->owner, $draft, pdfUpload("k{$i}.pdf", "body {$i} ".microtime()));
            app(PostVoucher::class)($this->owner, $draft);
        }

        $dir = $this->dir.'-q'.$vouchers;
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(JsonDumpWriter::class)->write($this->company, $dir);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        File::deleteDirectory($dir);

        return $queries;
    };

    $few = $count(10);
    $many = $count(90);

    expect(Voucher::query()->count())->toBe(100)
        ->and($many)->toBe($few);
});
