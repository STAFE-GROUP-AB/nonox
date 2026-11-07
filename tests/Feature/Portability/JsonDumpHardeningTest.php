<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Accounting\LedgerHash;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherRow;
use App\Support\Portability\DumpChainVerifier;
use App\Support\Portability\JsonDumpWriter;
use App\Support\Portability\PersonalNumber;
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
    $this->dir = sys_get_temp_dir().'/nonox-dump-h-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    JsonDumpWriter::$chunk = 500;
});

/** @return array<string, mixed> */
function dumpOf(string $dir): array
{
    return json_decode((string) file_get_contents($dir.'/dump.json'), true, 512, JSON_THROW_ON_ERROR);
}

function schemaProblems(array $dump): array
{
    return SchemaValidator::forDump()->validateJson((string) json_encode($dump));
}

it('reports a posted voucher whose fiscal year is not in the dump', function () {
    Bookkeeping::post($this->owner, $this->company, 100_00, 1930, 3001, '2026-03-02');
    app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $dump = dumpOf($this->dir);
    $dump['vouchers'][0]['fiscal_year_id'] = 999_999;

    expect(implode(' ', DumpChainVerifier::verify($dump)))->toContain('is not in the dump');
});

it('reports an unknown voucher status and missing keys instead of throwing', function () {
    Bookkeeping::post($this->owner, $this->company, 100_00, 1930, 3001, '2026-03-02');
    app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $dump = dumpOf($this->dir);
    $dump['vouchers'][0]['status'] = 'POSTED ';
    $dump['vouchers'][] = ['id' => 'abc'];
    $dump['vouchers'][] = 'not a voucher';
    unset($dump['vouchers'][0]['rows']);

    $problems = implode("\n", DumpChainVerifier::verify($dump));

    expect($problems)->toContain('missing')
        ->and($problems)->toContain('#2');

    $dump = dumpOf($this->dir);
    $dump['vouchers'][0]['status'] = 'POSTED ';

    expect(implode(' ', DumpChainVerifier::verify($dump)))->toContain('unknown status');

    $dump = dumpOf($this->dir);
    $dump['vouchers'][0]['fiscal_year_id'] = ['x'];
    $dump['vouchers'][0]['hash'] = 5;

    expect(DumpChainVerifier::verify($dump))->not->toBe([]);
    expect(DumpChainVerifier::verify(['vouchers' => []]))->not->toBe([]);
});

it('builds the same canonical JSON as the ledger for every field the hash covers', function () {
    CostCenter::create(['code' => '10', 'name' => 'Malmö']);
    Project::create(['code' => 'P1', 'name' => 'Kök']);
    $other = Bookkeeping::member($this->company, 'accountant');

    $draft = app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-03-02',
        'description' => 'Försäljning à/b "café" — 50 % \\ ok',
        'series' => 'A',
        'rows' => [
            ['account' => 1930, 'debit_ore' => 12_345, 'description' => 'Rad å/ä/ö', 'cost_center' => '10', 'project' => 'P1'],
            ['account' => 3001, 'credit_ore' => 12_345],
        ],
    ]);
    VoucherRow::query()->where('voucher_id', $draft->id)->orderBy('id')->limit(1)->get()->each(fn (VoucherRow $row) => DB::table('voucher_rows')->where('id', $row->id)->update(['quantity' => '2.5']));
    $first = app(PostVoucher::class)($other, $draft);
    $corrected = app(CorrectVoucher::class)($this->owner, $first->fresh(), 'Fel belopp');

    app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $dump = dumpOf($this->dir);

    foreach ([$first, $corrected] as $voucher) {
        $voucher = Voucher::query()->with(['rows.account', 'series'])->findOrFail($voucher->id);
        $exported = collect($dump['vouchers'])->firstWhere('id', $voucher->id);

        expect(DumpChainVerifier::canonicalJson($this->company->id, $exported))->toBe(LedgerHash::canonicalJson($voucher))
            ->and($exported['posted_by'])->not->toBeNull();
    }

    $firstExport = collect($dump['vouchers'])->firstWhere('id', $first->id);
    $correctionExport = collect($dump['vouchers'])->firstWhere('id', $corrected->id);

    expect($firstExport['posted_by'])->toBe($other->id)
        ->and($firstExport['posted_by'])->not->toBe($this->owner->id)
        ->and($firstExport['rows'][0]['quantity'])->toBeString()->toBe('2.5000')
        ->and($firstExport['rows'][0]['cost_center_id'])->not->toBeNull()
        ->and($firstExport['rows'][0]['project_id'])->not->toBeNull()
        ->and($correctionExport['corrects_voucher_id'])->toBe($first->id)
        ->and(DumpChainVerifier::verify($dump))->toBe([]);
});

it('crosses chunk borders with three queries more per extra chunk', function () {
    JsonDumpWriter::$chunk = 5;

    $count = function (string $suffix): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(JsonDumpWriter::class)->write($this->company, $this->dir.$suffix);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();
        File::deleteDirectory($this->dir.$suffix);

        return $total;
    };

    for ($i = 0; $i < 10; $i++) {
        Bookkeeping::post($this->owner, $this->company, 100_00 + $i, 1930, 3001, '2026-03-02');
    }
    $two = $count('-a');

    for ($i = 0; $i < 5; $i++) {
        Bookkeeping::post($this->owner, $this->company, 200_00 + $i, 1930, 3001, '2026-03-03');
    }
    $three = $count('-b');

    expect($three - $two)->toBe(3);
});

it('creates the package private and removes all of it when writing fails', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, 49_00, 5410, 1930, '2026-03-05');
    app(AttachToVoucher::class)($this->owner, $draft, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%x\n%%EOF\n"));

    app(JsonDumpWriter::class)->write($this->company, $this->dir);

    expect(substr(sprintf('%o', fileperms($this->dir)), -4))->toBe('0700')
        ->and(substr(sprintf('%o', fileperms($this->dir.'/dump.json')), -4))->toBe('0600')
        ->and(substr(sprintf('%o', fileperms($this->dir.'/attachments')), -4))->toBe('0700');

    foreach (File::files($this->dir.'/attachments') as $file) {
        expect(substr(sprintf('%o', fileperms($file->getPathname())), -4))->toBe('0600');
    }

    File::deleteDirectory($this->dir);
    JsonDumpWriter::$chunk = 0;

    expect(fn () => app(JsonDumpWriter::class)->write($this->company, $this->dir))->toThrow(InvalidArgumentException::class)
        ->and(file_exists($this->dir))->toBeFalse();
});

it('refuses to write into a directory that already has content', function () {
    File::ensureDirectoryExists($this->dir);
    file_put_contents($this->dir.'/something', 'x');

    expect(fn () => app(JsonDumpWriter::class)->write($this->company, $this->dir))->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->dir.'/something'))->toBe('x');
});

it('fails closed without a saved company and leaves no current company behind', function () {
    CurrentCompany::forget();
    $this->actingAs(Bookkeeping::member($this->company, 'owner'));
    $unsaved = new Company(['name' => 'Ej sparat']);

    expect(fn () => app(JsonDumpWriter::class)->write($unsaved, $this->dir))->toThrow(RuntimeException::class)
        ->and(file_exists($this->dir))->toBeFalse();

    CurrentCompany::forget();
    auth()->logout();
    app(JsonDumpWriter::class)->write($this->company, $this->dir);

    expect(CurrentCompany::id())->toBeNull();
});

it('leaves out a sole trader\'s and a private person\'s organisation number unless personal numbers are asked for', function () {
    $this->company->forceFill(['legal_form' => 'EF', 'org_number' => '8507099805'])->save();
    app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Privat Person', 'org_number' => '8507099805', 'payment_terms_days' => 30]);
    app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Bolaget AB', 'org_number' => '5563343689', 'payment_terms_days' => 30]);
    Supplier::create(['number' => 1, 'name' => 'Enskild firma', 'org_number' => '8507099805', 'country' => 'SE']);

    app(JsonDumpWriter::class)->write($this->company, $this->dir);
    $raw = (string) file_get_contents($this->dir.'/dump.json');
    $dump = dumpOf($this->dir);

    expect($raw)->not->toContain('850709')
        ->and($raw)->toContain('556334-3689')
        ->and($dump['company']['org_number'])->toBeNull()
        ->and(schemaProblems($dump))->toBe([]);

    File::deleteDirectory($this->dir);
    app(JsonDumpWriter::class)->write($this->company, $this->dir, includePersonalNumbers: true);

    expect((string) file_get_contents($this->dir.'/dump.json'))->toContain('850709');
});

it('leaves out the organisation number of a sole-trader company whatever it looks like', function () {
    $this->company->forceFill(['legal_form' => 'EF', 'org_number' => '556334-3689'])->save();

    app(JsonDumpWriter::class)->write($this->company->fresh(), $this->dir);

    expect(dumpOf($this->dir)['company']['org_number'])->toBeNull();
});

it('tells personal numbers from organisation numbers', function (string $number, bool $personal) {
    expect(PersonalNumber::looksLike($number))->toBe($personal);
})->with([
    ['850709-9805', true],
    ['198507099805', true],
    ['556334-3689', false],
    ['8507099806', false],
    ['', false],
]);

it('does not export an attachment whose recorded SHA-256 is not a plain hash', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, 49_00, 5410, 1930, '2026-03-05');
    DB::table('voucher_attachments')->insert(['voucher_id' => $draft->id, 'company_id' => $this->company->id, 'file_path' => 'x/y.pdf', 'original_name' => 'y.pdf', 'mime' => 'application/pdf', 'size' => 3, 'sha256' => str_repeat('Z', 64), 'created_at' => now(), 'updated_at' => now()]);

    $result = app(JsonDumpWriter::class)->write($this->company, $this->dir);

    expect($result['warnings'])->toHaveCount(1)
        ->and(file_exists($this->dir.'/attachments') ? File::files($this->dir.'/attachments') : [])->toBe([])
        ->and(VoucherAttachment::query()->count())->toBe(1);
});

it('refuses unsupported schema keywords anywhere and keywords next to a reference', function () {
    expect(fn () => new SchemaValidator(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'format' => 'date']]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SchemaValidator(['properties' => ['a' => ['$ref' => '#/$defs/x', 'type' => 'string']], '$defs' => ['x' => ['type' => 'string']]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SchemaValidator(['properties' => ['a' => ['$ref' => '#/$defs/missing']]]))->toThrow(InvalidArgumentException::class);
});

it('tells objects from lists and does not let a trailing newline pass a pattern', function () {
    $validator = new SchemaValidator([
        'type' => 'object',
        'properties' => [
            'list' => ['type' => 'array'],
            'map' => ['type' => 'object'],
            'sha' => ['type' => 'string', 'pattern' => '^[0-9a-f]{4}\\z'],
        ],
    ]);

    expect($validator->validateJson('{"list":{"0":"a"}}'))->not->toBe([])
        ->and($validator->validateJson('{"map":[]}'))->not->toBe([])
        ->and($validator->validateJson('{"list":[],"map":{}}'))->toBe([])
        ->and($validator->validateJson('{"sha":"abcd\\n"}'))->not->toBe([])
        ->and($validator->validateJson('{"sha":"abcd"}'))->toBe([]);
});
