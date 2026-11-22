<?php

declare(strict_types=1);

use App\Actions\Signing\PruneSigningLinks;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Jobs\RunSigningBulkSend;
use App\Models\SigningEnvelope;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\ManageSigningBulkSends;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Actions\ProcessSigningBulkSend;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Models\SigningBulkSendRow;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;

/*
 * Bulk send for signing (ADR-046 S15), the review fixes: a run never hangs,
 * a sent row is never recorded as failed, the template is pinned from the
 * preview, the example file is safe in a spreadsheet and abandoned uploads
 * are removed.
 */

function s15rTemplate($user, $company, string $name = 'Samarbetsavtal', array $extra = []): SigningTemplate
{
    $path = tempnam(sys_get_temp_dir(), 's15r');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        $document = app(StoreSigningDocument::class)($user, $company, $path, 'Avtal.pdf', $name);
    } finally {
        @unlink($path);
    }

    return app(ManageSigningTemplates::class)->create($user, $company, [
        'name' => $name,
        'document_type' => 'DOCUMENT',
        'roles' => [
            ['label' => 'Kund', 'step' => 1, 'verification' => 'NONE'],
            ['label' => 'Säljare', 'step' => 2, 'verification' => 'ACCESS_CODE'],
        ],
        'fields' => [
            ['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'Kund', 'required' => true],
            ['signer' => 1, 'type' => 'SIGNATURE', 'page' => 2, 'x' => 5000, 'y' => 9000, 'width' => 20000, 'height' => 2000, 'label' => 'Säljare', 'required' => true],
        ],
        'deadline_days' => 14,
        ...$extra,
    ], $document);
}

function s15rFile(int $rows): string
{
    $lines = ['Kund e-post;Säljare e-post;Säljare åtkomstkod'];

    for ($i = 1; $i <= $rows; $i++) {
        $lines[] = "k{$i}@example.test;s{$i}@example.test;Avtal-2027-{$i}x";
    }

    $path = tempnam(sys_get_temp_dir(), 's15rcsv');
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    Queue::fake();
    FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
    $this->template = s15rTemplate($this->owner, $this->company);
    $this->runs = app(ManageSigningBulkSends::class);
    RateLimiter::clear('mail-quota:documents:'.$this->company->id);
});

it('stops a run, skipping what is left and emptying every payload, when the job runs out of attempts, and retries by time not by count', function () {
    $path = s15rFile(3);
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);

    $job = new RunSigningBulkSend($this->company->id, $run->id);
    expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHours(2)->getTimestamp())
        ->and(property_exists($job, 'tries'))->toBeFalse();

    $job->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe('CANCELLED')
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', 'SKIPPED')->count())->toBe(3)
        ->and(DB::table('signing_bulk_send_rows')->where('bulk_send_id', $run->id)->whereNotNull('payload')->count())->toBe(0);
});

it('never leaves a sent row as skipped: a row with an envelope is settled as sent when the run is cancelled', function () {
    $path = s15rFile(2);
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);
    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

    $row = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->orderBy('row_number')->firstOrFail();
    $documentId = SigningEnvelope::query()->whereKey($row->envelope_id)->value('signable_id');
    DB::table('signing_bulk_send_rows')->where('id', $row->id)->update(['status' => 'PROCESSING', 'envelope_id' => null, 'document_id' => $documentId]);
    DB::table('signing_bulk_sends')->where('id', $run->id)->update(['status' => 'RUNNING', 'finished_at' => null]);

    $this->runs->cancel($this->owner, $this->company, $run);

    $row->refresh();
    $run->refresh();
    expect($row->status)->toBe('SENT')->and($row->envelope_id)->not->toBeNull()
        ->and($run->status)->toBe('CANCELLED')->and($run->sent_count)->toBe(2)->and($run->failed_count)->toBe(0);
});

it('only lets a worker settle a row it still holds', function () {
    $path = s15rFile(1);
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);
    $row = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->firstOrFail();
    $row->forceFill(['status' => 'SKIPPED'])->save();

    (new ReflectionMethod(ProcessSigningBulkSend::class, 'settle'))->invoke(app(ProcessSigningBulkSend::class), $row, 'FAILED', 'x');

    expect($row->refresh()->status)->toBe('SKIPPED');
});

it('pins the template: a change after the preview refuses the confirmation, and a change during the run stops it', function () {
    $change = fn () => app(ManageSigningTemplates::class)->update($this->owner, $this->company, $this->template->refresh(), [
        'name' => 'Samarbetsavtal',
        'roles' => [
            ['key' => 'kund', 'label' => 'Kund', 'step' => 1, 'verification' => 'NONE'],
            ['key' => 'saljare', 'label' => 'Säljare', 'step' => 2, 'verification' => 'NONE'],
        ],
        'deadline_days' => 14,
    ]);

    $path = s15rFile(2);
    $preview = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    $running = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $running);

    $change();

    expect(fn () => $this->runs->confirm($this->owner, $this->company, $preview))->toThrow(ValidationException::class, 'ändrats')
        ->and($preview->refresh()->status)->toBe('PREVIEW');

    app(ProcessSigningBulkSend::class)($this->company->id, $running->id);

    expect($running->refresh()->status)->toBe('CANCELLED')
        ->and(SigningEnvelope::query()->count())->toBe(0)
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $running->id)->first()->reason)->toContain('mallen ändrades')
        ->and(DB::table('signing_bulk_send_rows')->where('bulk_send_id', $running->id)->whereNotNull('payload')->count())->toBe(0);
});

it('refuses the confirmation when a role was removed after the preview', function () {
    $path = s15rFile(1);
    $preview = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);

    app(ManageSigningTemplates::class)->update($this->owner, $this->company, $this->template->refresh(), [
        'name' => 'Samarbetsavtal',
        'roles' => [['key' => 'kund', 'label' => 'Kund', 'step' => 1, 'verification' => 'NONE']],
        'deadline_days' => 14,
    ]);

    expect(fn () => $this->runs->confirm($this->owner, $this->company, $preview))->toThrow(ValidationException::class, 'ändrats');
});

it('queues a run that stands still again, and stops one that has stood still for seven days', function () {
    $path = s15rFile(2);
    $slow = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    $dead = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $slow);
    $this->runs->confirm($this->owner, $this->company, $dead);
    DB::table('signing_bulk_sends')->where('id', $slow->id)->update(['started_at' => now()->subHours(3)]);
    DB::table('signing_bulk_sends')->where('id', $dead->id)->update(['started_at' => now()->subDays(8)]);
    Queue::fake();

    app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    Queue::assertPushed(RunSigningBulkSend::class, fn (RunSigningBulkSend $job): bool => $job->bulkSendId === $slow->id);
    Queue::assertNotPushed(RunSigningBulkSend::class, fn (RunSigningBulkSend $job): bool => $job->bulkSendId === $dead->id);
    expect($slow->refresh()->status)->toBe('RUNNING')
        ->and($dead->refresh()->status)->toBe('CANCELLED')
        ->and(DB::table('signing_bulk_send_rows')->where('bulk_send_id', $dead->id)->whereNotNull('payload')->count())->toBe(0);
});

it('writes the example file so that no cell runs as a formula', function () {
    $template = s15rTemplate($this->owner, $this->company, 'Formelmall', ['roles' => [
        ['label' => '=SUM(A1)', 'step' => 1, 'verification' => 'NONE'],
        ['label' => 'Säljare', 'step' => 2, 'verification' => 'NONE'],
    ]]);

    $csv = (string) $this->get(route('signing.bulk-sends.example', $template->id))->assertOk()->getContent();

    expect($csv)->toContain("'=SUM(A1) e-post")->and($csv)->not->toContain('"=SUM');
});

it('removes abandoned temporary uploads older than a day on a schedule', function () {
    $storage = FileUploadConfiguration::storage();
    $directory = FileUploadConfiguration::path();
    $storage->put("{$directory}/gammal.csv", 'a@example.test');
    $storage->put("{$directory}/ny.csv", 'b@example.test');
    touch($storage->path("{$directory}/gammal.csv"), now()->subHours(25)->timestamp);

    $this->artisan('uploads:prune-temporary')->expectsOutputToContain('Tog bort 1')->assertExitCode(0);

    expect($storage->exists("{$directory}/gammal.csv"))->toBeFalse()->and($storage->exists("{$directory}/ny.csv"))->toBeTrue();

    $scheduled = collect(app(Schedule::class)->events())->contains(fn ($event): bool => str_contains((string) $event->command, 'uploads:prune-temporary'));
    expect($scheduled)->toBeTrue();
});

it('keeps a run cancelled while a row was being sent cancelled, and writes no completed entry', function () {
    $path = s15rFile(1);
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);

    SigningEnvelope::created(function () use ($run): void {
        DB::table('signing_bulk_sends')->where('id', $run->id)->update(['status' => 'CANCELLED', 'finished_at' => now()]);
    });

    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

    expect($run->refresh()->status)->toBe('CANCELLED')
        ->and(DB::table('audit_logs')->where('action', 'signing.bulk_send_completed')->count())->toBe(0)
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->value('status'))->toBe('SENT');
});

it('skips rows left pending on an ended run, and counts them as not sent', function () {
    $path = s15rFile(3);
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);
    DB::table('signing_bulk_sends')->where('id', $run->id)->update(['status' => 'CANCELLED', 'finished_at' => now()]);

    app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    $run->refresh();
    expect(SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', 'SKIPPED')->count())->toBe(3)
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', 'PENDING')->count())->toBe(0)
        ->and($run->failed_count)->toBe(3);
});

it('prunes a company that has only bulk sends', function () {
    $path = s15rFile(1);
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    DB::table('signing_bulk_sends')->where('id', $run->id)->update(['created_at' => now()->subHours(30)]);
    DB::table('signing_documents')->where('company_id', $this->company->id)->delete();

    $this->artisan('signing:prune')->assertExitCode(0);

    expect(DB::table('signing_bulk_sends')->where('id', $run->id)->exists())->toBeFalse();
});
