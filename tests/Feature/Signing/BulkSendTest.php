<?php

declare(strict_types=1);

use App\Actions\Signing\PruneSigningLinks;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Jobs\RunSigningBulkSend;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningField;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\ManageSigningBulkSends;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Actions\ProcessSigningBulkSend;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\BulkSendPage;
use App\Modules\Signing\Livewire\BulkSendsPage;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Modules\Signing\Models\SigningBulkSendRow;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;

/*
 * Bulk send for signing (ADR-046 amendment 2026-11-18, gap 8, package S15):
 * the file is read and every row checked before anything is sent, a person
 * confirms, the envelopes are made in the background exactly like the wizard
 * makes one from the template, the mail quota is respected, a run can be
 * resumed and cancelled, and no address is left behind. The PDF engine is
 * the fake one.
 */

/** @return array{0: SigningTemplate} */
function s15Template(User $user, Company $company, string $name = 'Samarbetsavtal', array $extra = []): SigningTemplate
{
    $path = tempnam(sys_get_temp_dir(), 's15');
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
            ['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'Kundens underskrift', 'required' => true],
            ['signer' => 1, 'type' => 'SIGNATURE', 'page' => 2, 'x' => 5000, 'y' => 9000, 'width' => 20000, 'height' => 2000, 'label' => 'Säljarens underskrift', 'required' => true],
        ],
        'deadline_days' => 14,
        'copies' => [['email' => 'arkiv@example.test', 'name' => 'Arkivet', 'language' => 'sv']],
        ...$extra,
    ], $document);
}

/** A file the page or the action reads: the header row and the lines, semicolon-separated. */
function s15File(array $lines, string $header = 'Kund e-post;Kund namn;Kund språk;Säljare e-post;Säljare åtkomstkod'): string
{
    $path = tempnam(sys_get_temp_dir(), 's15csv');
    file_put_contents($path, implode("\n", [$header, ...$lines])."\n");

    return $path;
}

function s15Lines(int $count, string $prefix = 'kund'): array
{
    $lines = [];

    for ($i = 1; $i <= $count; $i++) {
        $lines[] = "{$prefix}{$i}@example.test;Kund {$i};sv;saljare{$i}@example.test;Avtal-2027-{$i}x";
    }

    return $lines;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    $this->engine = FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
    $this->template = s15Template($this->owner, $this->company);
    $this->runs = app(ManageSigningBulkSends::class);
    RateLimiter::clear('mail-quota:documents:'.$this->company->id);
});

afterEach(function () {
    RateLimiter::clear('mail-quota:documents:'.$this->company->id);
});

it('reads the file, checks every row and sends nothing', function () {
    $path = s15File([
        'ok@example.test;Olle Ok;sv;saljare@example.test;Avtal-2027-aaa',
        ';Saknar;sv;saljare2@example.test;Avtal-2027-bbb',
        'inte-en-adress;Fel;sv;saljare3@example.test;Avtal-2027-ccc',
        'ok@example.test;Dubblett;sv;saljare@example.test;Avtal-2027-aaa',
        'sprak@example.test;Språk;de;saljare4@example.test;Avtal-2027-ddd',
        'kod@example.test;Kod;sv;saljare5@example.test;123',
        'samma@example.test;Samma;sv;samma@example.test;Avtal-2027-eee',
        'sista@example.test;Sista;en;saljare6@example.test;Avtal-2027-fff',
    ]);

    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);

    expect($run->status)->toBe('PREVIEW')
        ->and($run->total_rows)->toBe(8)
        ->and($run->valid_rows)->toBe(2)
        ->and($run->invalid_rows)->toBe(6);

    $rows = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->orderBy('row_number')->get()->keyBy('row_number');

    expect($rows->pluck('status')->all())->toBe(['PENDING', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'PENDING'])
        ->and($rows[3]->reason)->toContain('Kund')
        ->and($rows[4]->reason)->toContain('inte giltig')
        ->and($rows[5]->reason)->toContain('rad 2')
        ->and($rows[6]->reason)->toContain('sv eller en')
        ->and($rows[7]->reason)->toContain('Åtkomstkod')
        ->and($rows[8]->reason)->toContain('Samma e-postadress')
        ->and($rows[9]->payload['signers'][0]['language'])->toBe('en')
        ->and(SigningEnvelope::query()->count())->toBe(0)
        ->and(SigningRequest::query()->count())->toBe(0);

    // The reasons never hold an address, and the addresses are stored encrypted.
    expect($rows->pluck('reason')->filter()->implode(' '))->not->toContain('@')
        ->and(DB::table('signing_bulk_send_rows')->where('bulk_send_id', $run->id)->whereNotNull('payload')->value('payload'))->not->toContain('example.test');

    $audit = AuditLog::query()->where('action', 'signing.bulk_send_prepared')->latest('id')->firstOrFail();
    expect(json_encode($audit->payload))->not->toContain('example.test')->and($audit->payload['valid'])->toBe(2);
});

it('refuses a file that cannot be used at all', function () {
    $missing = s15File(['kund@example.test;Kund'], 'Kund e-post;Kund namn');
    expect(fn () => $this->runs->prepare($this->owner, $this->company, $this->template, $missing))->toThrow(ValidationException::class, 'Säljare e-post');

    $empty = s15File([]);
    expect(fn () => $this->runs->prepare($this->owner, $this->company, $this->template, $empty))->toThrow(ValidationException::class);

    $big = s15File(s15Lines(501));
    expect(fn () => $this->runs->prepare($this->owner, $this->company, $this->template, $big))->toThrow(ValidationException::class, '500');

    $limit = s15File(s15Lines(500));
    expect($this->runs->prepare($this->owner, $this->company, $this->template, $limit)->total_rows)->toBe(500);

    expect(SigningBulkSend::query()->count())->toBe(1);
    array_map('unlink', [$missing, $empty, $big, $limit]);
});

it('matches the columns however they are spelled and ignores the rest', function () {
    $path = s15File(['kund@example.test;x;saljare@example.test;Avtal-2027-aaa;intern'], 'KUND E-POST;Anteckning;Säljare epost;Säljare Åtkomstkod;Övrigt');
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);

    expect($run->valid_rows)->toBe(1);
});

it('sends nothing before a person confirms, and then makes one envelope per valid row like the wizard does', function () {
    $path = s15File([...s15Lines(3), 'trasig;Fel;sv;x@example.test;Avtal-2027-zzz']);
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);

    expect(SigningEnvelope::query()->count())->toBe(0);

    $this->runs->confirm($this->owner, $this->company, $run);

    $run->refresh();
    expect($run->status)->toBe('COMPLETED')->and($run->sent_count)->toBe(3)->and($run->failed_count)->toBe(0)->and($run->confirmed_at)->not->toBeNull()->and($run->finished_at)->not->toBeNull();

    $envelopes = SigningEnvelope::query()->with('signers')->orderBy('created_at')->get();
    expect($envelopes)->toHaveCount(3);

    $first = $envelopes->first();
    expect($first->template_id)->toBe($this->template->id)
        ->and($first->signers)->toHaveCount(2)
        ->and($first->signers->pluck('step')->sort()->values()->all())->toBe([1, 2])
        ->and($first->signers->firstWhere('step', 2)->verification->value)->toBe('ACCESS_CODE')
        ->and($first->signers->firstWhere('step', 2)->access_code_hash)->not->toBeNull()
        ->and(SigningField::query()->where('envelope_id', $first->id)->count())->toBe(2)
        ->and($first->confidential)->toBeFalse();

    $rows = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->orderBy('row_number')->get();
    expect($rows->pluck('status')->all())->toBe(['SENT', 'SENT', 'SENT', 'INVALID'])
        ->and($rows->pluck('payload')->filter()->all())->toBe([])
        ->and($rows->whereNotNull('envelope_id')->pluck('envelope_id')->sort()->values()->all())->toBe($envelopes->pluck('id')->sort()->values()->all());

    // Each envelope has its own copy of the template's file, and the template is untouched.
    expect($envelopes->pluck('signable_id')->unique())->toHaveCount(3)
        ->and(SigningTemplate::query()->find($this->template->id)->sha256)->toBe($this->template->sha256);

    $actions = AuditLog::query()->where('action', 'like', 'signing.bulk_send_%')->pluck('action')->all();
    expect($actions)->toContain('signing.bulk_send_prepared', 'signing.bulk_send_confirmed', 'signing.bulk_send_completed');
    expect(AuditLog::query()->where('action', 'like', 'signing.bulk_send_%')->get()->map(fn ($log) => json_encode($log->payload))->implode(' '))->not->toContain('example.test');
});

it('only confirms a preview, only once, and never an empty one', function () {
    $path = s15File([';Fel;sv;;']);
    $empty = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);

    expect(fn () => $this->runs->confirm($this->owner, $this->company, $empty))->toThrow(ValidationException::class, 'Ingen rad');

    $path = s15File(s15Lines(1));
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);

    expect(fn () => $this->runs->confirm($this->owner, $this->company, $run))->toThrow(ValidationException::class, 'redan startat')
        ->and(SigningEnvelope::query()->count())->toBe(1);
});

it('refuses an archived template and a confirmation after the template was archived', function () {
    $path = s15File(s15Lines(1));
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    app(ManageSigningTemplates::class)->archive($this->owner, $this->company, $this->template);

    expect(fn () => $this->runs->confirm($this->owner, $this->company, $run))->toThrow(ValidationException::class, 'arkiverad')
        ->and(fn () => $this->runs->prepare($this->owner, $this->company, $this->template->refresh(), $path))->toThrow(ValidationException::class, 'aktiv mall');
    @unlink($path);
});

it('needs signing:manage and the module', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $path = s15File(s15Lines(1));

    expect(fn () => $this->runs->prepare($bookkeeper, $this->company, $this->template, $path))->toThrow(AuthorizationException::class);

    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);

    expect(fn () => $this->runs->confirm($bookkeeper, $this->company, $run))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->runs->cancel($bookkeeper, $this->company, $run))->toThrow(AuthorizationException::class);

    $this->actingAs($bookkeeper);
    Livewire::test(BulkSendsPage::class)->assertStatus(404);
    Livewire::test(BulkSendPage::class, ['bulkSend' => $run->id])->assertStatus(404);

    [$otherOwner, $without] = Bookkeeping::company();
    CurrentCompany::set($without);
    expect(fn () => $this->runs->prepare($otherOwner, $without, $this->template, s15File(s15Lines(1))))->toThrow(NotFoundHttpException::class);
});

it('waits for the mail quota instead of failing rows, and goes on when it frees up', function () {
    Queue::fake();
    $path = s15File(s15Lines(4));
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);
    Queue::assertPushed(RunSigningBulkSend::class, 1);

    // The quota is almost used up: one envelope takes one mail (the customer's step 1), so two fit.
    MailQuota::count($this->company, 'documents', MailQuota::LIMITS['documents'] - 2);
    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

    $run->refresh();
    expect($run->status)->toBe('RUNNING')
        ->and(SigningEnvelope::query()->count())->toBe(2)
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', 'PENDING')->count())->toBe(2)
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', 'FAILED')->count())->toBe(0);
    Queue::assertPushed(RunSigningBulkSend::class, fn (RunSigningBulkSend $job): bool => $job->delay !== null);

    RateLimiter::clear('mail-quota:documents:'.$this->company->id);
    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

    $run->refresh();
    expect($run->status)->toBe('COMPLETED')->and($run->sent_count)->toBe(4)->and(SigningEnvelope::query()->count())->toBe(4);
});

it('is resumable and idempotent: a row left in the middle is settled from what exists and nothing is sent twice', function () {
    Queue::fake();
    $path = s15File(s15Lines(3));
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);

    // Run once to the end, then pretend the worker died after row 1's envelope but before its row was saved.
    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);
    $row = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->orderBy('row_number')->firstOrFail();
    $documentId = SigningEnvelope::query()->whereKey($row->envelope_id)->value('signable_id');
    DB::table('signing_bulk_send_rows')->where('id', $row->id)->update(['status' => 'PROCESSING', 'envelope_id' => null, 'document_id' => $documentId]);
    DB::table('signing_bulk_sends')->where('id', $run->id)->update(['status' => 'RUNNING', 'finished_at' => null]);

    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);
    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

    expect(SigningEnvelope::query()->count())->toBe(3)
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', 'SENT')->count())->toBe(3)
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->whereNull('envelope_id')->count())->toBe(0);
});

it('puts a row whose envelope was never made back in the queue', function () {
    Queue::fake();
    $path = s15File(s15Lines(2));
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);

    $row = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->orderBy('row_number')->firstOrFail();
    $row->forceFill(['status' => 'PROCESSING'])->save();

    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

    expect(SigningEnvelope::query()->count())->toBe(2)->and($run->refresh()->status)->toBe('COMPLETED');
});

it('reports a row that cannot be sent without stopping the run', function () {
    Queue::fake();
    $path = s15File([...s15Lines(2)]);
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($this->owner, $this->company, $run);

    // A payload that the send refuses (the same address twice), as if a row had slipped through the preview.
    $row = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->orderBy('row_number')->firstOrFail();
    $payload = $row->payload;
    $payload['signers'][1]['email'] = $payload['signers'][0]['email'];
    $row->forceFill(['payload' => $payload])->save();

    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

    $run->refresh();
    $failed = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', 'FAILED')->get();
    expect($run->status)->toBe('COMPLETED')->and($run->sent_count)->toBe(1)->and($run->failed_count)->toBe(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed->first()->reason)->not->toContain('@')->and($failed->first()->payload)->toBeNull()
        // The document made for the failed row is not left behind.
        ->and(DB::table('signing_documents')->where('company_id', $this->company->id)->count())->toBe(2);
});

it('cancels a running run, skipping what is left and emptying the payloads, and throws a preview away', function () {
    Queue::fake();
    $path = s15File(s15Lines(3));
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    $preview = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);

    $this->runs->cancel($this->owner, $this->company, $preview);
    expect(SigningBulkSend::query()->whereKey($preview->id)->exists())->toBeFalse()
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $preview->id)->exists())->toBeFalse();

    $this->runs->confirm($this->owner, $this->company, $run);
    $this->runs->cancel($this->owner, $this->company, $run);

    $run->refresh();
    expect($run->status)->toBe('CANCELLED')
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', 'SKIPPED')->count())->toBe(3)
        ->and(DB::table('signing_bulk_send_rows')->where('bulk_send_id', $run->id)->whereNotNull('payload')->count())->toBe(0);

    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);
    expect(SigningEnvelope::query()->count())->toBe(0);
});

it('stops a run whose sender may no longer send', function () {
    Queue::fake();
    $admin = Bookkeeping::member($this->company, 'admin');
    $path = s15File(s15Lines(2));
    $run = $this->runs->prepare($admin, $this->company, $this->template, $path);
    @unlink($path);
    $this->runs->confirm($admin, $this->company, $run);
    DB::table('team_user')->where('team_id', $this->company->id)->where('user_id', $admin->id)->update(['role' => 'auditor']);

    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

    expect($run->refresh()->status)->toBe('CANCELLED')->and(SigningEnvelope::query()->count())->toBe(0);
});

it('limits the runs that are open at once', function () {
    $path = s15File(s15Lines(1));

    for ($i = 0; $i < ManageSigningBulkSends::MAX_OPEN; $i++) {
        $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    }

    expect(fn () => $this->runs->prepare($this->owner, $this->company, $this->template, $path))->toThrow(ValidationException::class);
    @unlink($path);
});

it('runs from the pages: upload, preview, confirm, result with a link to each envelope', function () {
    Queue::fake();
    $file = UploadedFile::fake()->createWithContent('rader.csv', "Kund e-post;Kund namn;Säljare e-post;Säljare åtkomstkod\nny@example.test;Ny Kund;s@example.test;Avtal-2027-ppp\nfel;Fel;s2@example.test;Avtal-2027-qqq\n");

    $page = Livewire::test(BulkSendsPage::class)
        ->set('templateId', $this->template->id)
        ->set('file', $file)
        ->call('read');

    $run = SigningBulkSend::query()->firstOrFail();
    $page->assertRedirect(route('signing.bulk-send', $run->id));
    expect(Storage::disk('local')->allFiles())->not->toContain('livewire-tmp')->and(SigningEnvelope::query()->count())->toBe(0);

    Livewire::test(BulkSendPage::class, ['bulkSend' => $run->id])
        ->assertSee('Inget har skickats än.')
        ->assertSee('ny@example.test')
        ->assertSee('Skicka 1 signeringar')
        ->call('confirm')
        ->assertSee('Pågår');

    Queue::assertPushed(RunSigningBulkSend::class);
    app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

    $envelope = SigningEnvelope::query()->firstOrFail();
    Livewire::test(BulkSendPage::class, ['bulkSend' => $run->id])
        ->assertSee('Klart')
        ->assertSee(route('signing.envelope', $envelope->id))
        ->assertDontSee('ny@example.test')
        ->set('show', 'problem')
        ->assertSee('inte giltig');

    $this->get(route('signing.bulk-sends'))->assertOk()->assertSee('Massutskick');
    $this->get(route('signing.bulk-send', $run->id))->assertOk();
});

it('warns in the preview when the mail quota will not cover the run', function () {
    $path = s15File(s15Lines(3));
    $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);
    MailQuota::count($this->company, 'documents', MailQuota::LIMITS['documents'] - 1);

    Livewire::test(BulkSendPage::class, ['bulkSend' => $run->id])->assertSeeHtml('data-testid="bulk-quota"');
});

it('serves an example file with the columns of the template', function () {
    $response = $this->get(route('signing.bulk-sends.example', $this->template->id))->assertOk();
    $csv = (string) $response->getContent();

    expect($csv)->toContain('"Kund e-post";"Kund namn";"Kund språk";"Säljare e-post"')->and($csv)->toContain('Säljare åtkomstkod');

    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));
    $this->get(route('signing.bulk-sends.example', $this->template->id))->assertNotFound();
});

it('does not run more queries for a long run than for a short one', function () {
    Queue::fake();
    $count = function (int $rows): int {
        $path = s15File(s15Lines($rows, "q{$rows}-"));
        $run = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
        @unlink($path);
        $this->runs->confirm($this->owner, $this->company, $run);
        app(ProcessSigningBulkSend::class)($this->company->id, $run->id);

        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::test(BulkSendPage::class, ['bulkSend' => $run->id])->assertOk();
        Livewire::test(BulkSendsPage::class)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count(2);
    $long = $count(30);

    expect($long)->toBeLessThanOrEqual($short + 1);
});

it('removes a preview nobody confirmed and old results when signing is pruned', function () {
    $path = s15File(s15Lines(2));
    $old = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    $fresh = $this->runs->prepare($this->owner, $this->company, $this->template, $path);
    @unlink($path);

    DB::table('signing_bulk_sends')->where('id', $old->id)->update(['created_at' => now()->subHours(25)]);

    app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect(SigningBulkSend::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(SigningBulkSendRow::query()->where('bulk_send_id', $old->id)->exists())->toBeFalse()
        ->and(SigningBulkSend::query()->whereKey($fresh->id)->exists())->toBeTrue();
});
