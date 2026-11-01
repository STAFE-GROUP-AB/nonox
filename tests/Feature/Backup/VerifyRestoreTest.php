<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    // A stand-in for the unpacked storage/app/private of a backup.
    $this->files = storage_path('framework/testing/restore-'.uniqid());
    File::ensureDirectoryExists($this->files);
    config(['filesystems.disks.local.root' => $this->files]);
    Storage::forgetDisk('local');

    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    $this->voucher = Bookkeeping::post($this->owner, $this->company, 12500);
    $this->attachment = app(AttachToVoucher::class)(
        $this->owner,
        $this->voucher,
        UploadedFile::fake()->createWithContent('kvitto.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n"),
    );
});

afterEach(function () {
    File::deleteDirectory($this->files);
});

function verifyRestore(string $files): PendingCommand
{
    // The test's own connection, so the command sees the data of the open transaction.
    return test()->artisan('backup:verify-restore', ['--connection' => DB::getDefaultConnection(), '--files' => $files]);
}

it('approves a restored copy whose ledger and files are intact', function () {
    verifyRestore($this->files)
        ->expectsOutputToContain('verifikationer och deras underlag är intakta')
        ->expectsOutputToContain('Återställningen är godkänd.')
        ->assertSuccessful();
});

it('fails when an attachment is missing from the restored files', function () {
    File::delete($this->files.'/'.$this->attachment->file_path);

    verifyRestore($this->files)
        ->expectsOutputToContain('Återställningen är underkänd')
        ->assertFailed();
});

it('fails when an attachment was restored with other contents', function () {
    File::put($this->files.'/'.$this->attachment->file_path, 'skadad');

    verifyRestore($this->files)->assertFailed();
});

it('asks for the restored files and database', function () {
    $this->artisan('backup:verify-restore')->assertExitCode(2);
    $this->artisan('backup:verify-restore', ['--files' => $this->files])->assertExitCode(2);
});
