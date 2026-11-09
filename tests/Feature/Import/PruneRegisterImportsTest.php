<?php

declare(strict_types=1);

use App\Actions\Accounting\ImportSie;
use App\Migration\Import\Actions\ConfirmRegisterImport;
use App\Migration\Import\RegisterKind;
use App\Models\RegisterImport;
use App\Models\VoucherAttachment;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * ADR-076, section 7: source files are kept 30 days after a finished import,
 * an unconfirmed preview 7 days, and the command touches import files only.
 */
beforeEach(function () {
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
});

function registerImportAged(object $test, int $daysOld, bool $finish): RegisterImport
{
    $csv = "Namn\nKund ".uniqid().' AB';
    $import = RegisterImports::upload($test->owner, $test->company, RegisterKind::Customers, RegisterImports::contents($csv));
    $import = RegisterImports::analyse($test->owner, $import, ['mapping' => ['name' => 'Namn']]);

    if ($finish) {
        app(ConfirmRegisterImport::class)($test->owner, $import);
    }

    $when = CarbonImmutable::now()->subDays($daysOld);
    CompanyScope::bypass(fn () => RegisterImport::query()->whereKey($import->id)->toBase()->update(['created_at' => $when, 'updated_at' => $when, 'finished_at' => $finish ? $when : null]));

    return $import;
}

it('deletes a finished import\'s file after 30 days and keeps the row with its counts and hash', function () {
    $old = registerImportAged($this, 31, true);
    $young = registerImportAged($this, 29, true);

    $this->artisan('register-import:prune')->assertSuccessful();

    $old = CompanyScope::bypass(fn () => RegisterImport::query()->findOrFail($old->id));
    $young = CompanyScope::bypass(fn () => RegisterImport::query()->findOrFail($young->id));

    expect($old->file_path)->toBeNull()->and($old->file_deleted_at)->not->toBeNull()
        ->and($old->sha256)->not->toBe('')->and($old->result)->not->toBeNull()
        ->and($young->file_path)->not->toBeNull()
        ->and(Storage::disk(ImportSie::DISK)->exists((string) $young->file_path))->toBeTrue();
});

it('deletes a preview that was never confirmed after 7 days', function () {
    $stale = registerImportAged($this, 8, false);
    $fresh = registerImportAged($this, 6, false);
    $path = CompanyScope::bypass(fn () => RegisterImport::query()->findOrFail($stale->id)->file_path);

    $this->artisan('register-import:prune')->assertSuccessful();

    expect(CompanyScope::bypass(fn () => RegisterImport::query()->findOrFail($stale->id)->file_path))->toBeNull()
        ->and(Storage::disk(ImportSie::DISK)->exists((string) $path))->toBeFalse()
        ->and(CompanyScope::bypass(fn () => RegisterImport::query()->findOrFail($fresh->id)->file_path))->not->toBeNull();
});

it('touches import files only: nothing else on the disk is deleted, whatever a row says', function () {
    Storage::disk(ImportSie::DISK)->put('underlag/1/x.pdf', 'underlag');
    Storage::disk(ImportSie::DISK)->put('sie-import/1/a.se', 'sie');

    $import = registerImportAged($this, 40, true);
    CompanyScope::bypass(fn () => RegisterImport::query()->whereKey($import->id)->toBase()->update(['file_path' => 'underlag/1/x.pdf']));

    $this->artisan('register-import:prune')->assertSuccessful();

    expect(Storage::disk(ImportSie::DISK)->exists('underlag/1/x.pdf'))->toBeTrue()
        ->and(Storage::disk(ImportSie::DISK)->exists('sie-import/1/a.se'))->toBeTrue()
        ->and(CurrentCompany::run($this->company, fn () => VoucherAttachment::query()->count()))->toBe(0);
});

it('is on the schedule', function () {
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

    expect($scheduled)->toContain('register-import:prune');
});
