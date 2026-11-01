<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\DeleteDraftVoucher;
use App\Actions\Accounting\RemoveAttachment;
use App\Domain\Accounting\ImmutableVoucher;
use App\Domain\Accounting\LedgerVerifier;
use App\Livewire\Accounting\VoucherAttachments;
use App\Livewire\Accounting\VoucherEditor;
use App\Models\AuditLog;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

function pdf(string $name = 'kvitto.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
}

it('stores underlag privately under a generated name with a checksum', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, date: '2026-06-01');

    $attachment = app(AttachToVoucher::class)($this->owner, $draft, pdf('Kvitto Clas Ohlson.pdf'));

    expect($attachment->original_name)->toBe('Kvitto Clas Ohlson.pdf')
        ->and($attachment->mime)->toBe('application/pdf')
        ->and($attachment->file_path)->toStartWith("underlag/{$this->company->id}/{$draft->id}/")
        ->and($attachment->file_path)->toEndWith('.pdf')
        ->and($attachment->file_path)->not->toContain('Clas')
        ->and($attachment->sha256)->toBe(hash('sha256', Storage::disk('local')->get($attachment->file_path)));

    Storage::disk('local')->assertExists($attachment->file_path);

    expect(AuditLog::forCompany($this->company)->where('action', 'voucher.attachment_added')->sole()->payload['name'])
        ->toBe('Kvitto Clas Ohlson.pdf');
});

it('judges the file by its content, not its name', function (UploadedFile $file, string $message) {
    $draft = Bookkeeping::draft($this->owner, $this->company, date: '2026-06-01');

    expect(fn () => app(AttachToVoucher::class)($this->owner, $draft, $file))->toThrow(ValidationException::class, $message);
    expect(VoucherAttachment::count())->toBe(0);
})->with([
    'text pretending to be a pdf' => [fn () => UploadedFile::fake()->createWithContent('kvitto.pdf', 'just text'), 'PDF eller en bild'],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('bild.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'PDF eller en bild'],
    'php' => [fn () => UploadedFile::fake()->createWithContent('kvitto.php', '<?php echo 1;'), 'PDF eller en bild'],
    'too large' => [fn () => UploadedFile::fake()->create('stor.pdf', 10241, 'application/pdf'), 'högst 10 MB'],
]);

it('accepts images', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, date: '2026-06-01');

    $attachment = app(AttachToVoucher::class)($this->owner, $draft, UploadedFile::fake()->image('kvitto.jpg', 400, 600));

    expect($attachment->mime)->toBe('image/jpeg')->and($attachment->isImage())->toBeTrue();
});

it('lets a receipt be added after posting but never removed', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, date: '2026-06-01');

    $attachment = app(AttachToVoucher::class)($this->owner, $voucher, pdf());

    expect(AuditLog::forCompany($this->company)->where('action', 'voucher.attachment_added')->sole()->payload['voucher_posted'])->toBeTrue()
        ->and(fn () => app(RemoveAttachment::class)($this->owner, $attachment))->toThrow(AuthorizationException::class)
        ->and(fn () => $attachment->delete())->toThrow(ImmutableVoucher::class);

    $refused = false;
    try {
        DB::transaction(fn () => DB::delete('delete from voucher_attachments where id = ?', [$attachment->id]));
    } catch (QueryException $exception) {
        $refused = str_contains($exception->getMessage(), 'cannot be removed');
    }

    expect($refused)->toBeTrue();
    Storage::disk('local')->assertExists($attachment->file_path);
});

it('never changes an attachment', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, date: '2026-06-01');
    $attachment = app(AttachToVoucher::class)($this->owner, $draft, pdf());

    expect(fn () => $attachment->update(['original_name' => 'annat.pdf']))->toThrow(LogicException::class);

    $refused = false;
    try {
        DB::transaction(fn () => DB::update('update voucher_attachments set sha256 = ? where id = ?', [str_repeat('1', 64), $attachment->id]));
    } catch (QueryException $exception) {
        $refused = str_contains($exception->getMessage(), 'cannot be changed');
    }

    expect($refused)->toBeTrue();
});

it('removes files from a draft, and with the draft', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, date: '2026-06-01');
    $first = app(AttachToVoucher::class)($this->owner, $draft, pdf('a.pdf'));
    $second = app(AttachToVoucher::class)($this->owner, $draft, pdf('b.pdf'));

    app(RemoveAttachment::class)($this->owner, $first);
    Storage::disk('local')->assertMissing($first->file_path);

    app(DeleteDraftVoucher::class)($this->owner, $draft);
    Storage::disk('local')->assertMissing($second->file_path);
    expect(VoucherAttachment::count())->toBe(0);
});

it('shows underlag inline with a safe content type', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, date: '2026-06-01');
    $attachment = app(AttachToVoucher::class)($this->owner, $voucher, pdf());

    $response = $this->get(route('accounting.vouchers.attachment', [$voucher, $attachment->id]))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        // Shown in a frame on the voucher page: NONOX's own pages may frame it, no other site may.
        ->and($response->headers->get('Content-Security-Policy'))->toStartWith("frame-ancestors 'self';");
});

it('answers 404 for another company\'s underlag or a mismatched voucher', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, date: '2026-06-01');
    $other = Bookkeeping::post($this->owner, $this->company, date: '2026-06-02');
    $attachment = app(AttachToVoucher::class)($this->owner, $voucher, pdf());

    $this->get(route('accounting.vouchers.attachment', [$other, $attachment->id]))->assertNotFound();

    [$stranger] = Bookkeeping::company();
    $this->actingAs($stranger);
    $this->get(route('accounting.vouchers.attachment', [$voucher, $attachment->id]))->assertNotFound();
});

it('detects a changed or missing file in the nightly check', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, date: '2026-06-01');
    $attachment = app(AttachToVoucher::class)($this->owner, $voucher, pdf('kvitto.pdf'));

    expect(app(LedgerVerifier::class)->verify($this->company)['ok'])->toBeTrue();

    Storage::disk('local')->put($attachment->file_path, 'förfalskat');
    expect(app(LedgerVerifier::class)->verify($this->company)['problems'])
        ->toContain('Verifikation A1: underlaget "kvitto.pdf" har ändrats.');

    Storage::disk('local')->delete($attachment->file_path);
    expect(app(LedgerVerifier::class)->verify($this->company)['problems'])
        ->toContain('Verifikation A1: underlaget "kvitto.pdf" saknas.');
});

it('attaches files chosen on a new voucher when it is posted', function () {
    Livewire::test(VoucherEditor::class)
        ->set('description', 'Med kvitto')
        ->set('rows', [
            ['account' => '6110', 'debit' => '100', 'credit' => '', 'description' => ''],
            ['account' => '1930', 'debit' => '', 'credit' => '100', 'description' => ''],
        ])
        ->set('uploads', [pdf('kvitto.pdf')])
        ->call('post')
        ->assertHasNoErrors();

    $voucher = Voucher::query()->with('attachments')->sole();

    expect($voucher->isPosted())->toBeTrue()
        ->and($voucher->attachments->pluck('original_name')->all())->toBe(['kvitto.pdf']);
});

it('does not post when a chosen file is refused', function () {
    Livewire::test(VoucherEditor::class)
        ->set('description', 'Fel fil')
        ->set('rows', [
            ['account' => '6110', 'debit' => '100', 'credit' => '', 'description' => ''],
            ['account' => '1930', 'debit' => '', 'credit' => '100', 'description' => ''],
        ])
        ->set('uploads', [UploadedFile::fake()->createWithContent('kvitto.pdf', 'just text')])
        ->call('post')
        ->assertHasErrors('uploads')
        ->assertSee('kvitto.pdf: Underlaget måste vara en PDF eller en bild');

    expect(Voucher::query()->sole()->isPosted())->toBeFalse();
});

it('adds and shows underlag on the voucher page with toasts', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, date: '2026-06-01');

    Livewire::test(VoucherAttachments::class, ['voucher' => $voucher])
        ->assertSee('Inget underlag ännu')
        ->set('uploads', [pdf('faktura.pdf')])
        ->assertDispatched('toast', message: 'Underlaget faktura.pdf är sparat.')
        ->assertSee('faktura.pdf')
        ->assertSee('<iframe', escape: false)
        ->assertDontSee('wire:click="remove(', escape: false);

    $this->get(route('accounting.vouchers.show', $voucher))->assertOk()->assertSee('Underlag');
});
