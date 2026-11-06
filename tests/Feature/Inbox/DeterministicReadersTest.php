<?php

declare(strict_types=1);

use App\Actions\Payables\SaveSupplier;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Modules\Inbox\Actions\ManageInbox;
use App\Modules\Inbox\Agents\DailyReportAgent;
use App\Modules\Inbox\Agents\InboxClassifierAgent;
use App\Modules\Inbox\Domain\DocumentType;
use App\Modules\Inbox\Domain\Interpreter;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\InboxTemplate;
use App\Modules\Inbox\Support\VirusScanner;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Inbox;

/*
 * Acceptance: with NONOX AI off, SIE4 and Peppol/UBL are read by the
 * deterministic readers and everything else lands in the manual queue with
 * the attachment visible. Plus the fraud and duplicate warnings.
 */

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00:00'));
    [$this->owner, $this->company] = Inbox::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    InboxClassifierAgent::fake();
    DailyReportAgent::fake();
});

function grossisten(string $bankgiro = '765-4320'): Supplier
{
    return app(SaveSupplier::class)(test()->owner, test()->company, [
        'name' => 'Grossisten Exempel AB', 'org_number' => '559900-1236', 'email' => 'faktura@grossisten.example',
        'bankgiro' => $bankgiro, 'payment_terms_days' => 30, 'default_account' => 4010,
    ]);
}

it('reads a SIE4 file into voucher proposals and posts them with the mail as underlag', function () {
    Inbox::allow($this->company, 'export@kassaexport.example');

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('sie.eml')));

    expect($message->status)->toBe(MessageStatus::DraftReady)
        ->and($message->interpreted_by)->toBe(Interpreter::Sie)
        ->and($message->document_type)->toBe(DocumentType::Sie)
        ->and($message->proposal['vouchers'])->toHaveCount(2)
        ->and($message->proposal['vouchers'][0]['date'])->toBe('2026-10-30')
        ->and($message->proposal['vouchers'][0]['rows'])->toBe([
            ['account' => 1910, 'debit_ore' => 125_000, 'credit_ore' => 0, 'description' => ''],
            ['account' => 3001, 'debit_ore' => 0, 'credit_ore' => 100_000, 'description' => ''],
            ['account' => 2611, 'debit_ore' => 0, 'credit_ore' => 25_000, 'description' => ''],
        ]);

    $approved = app(ApproveInboxMessage::class)($this->owner, $message, $message->proposal);

    expect($approved->voucher_ids)->toHaveCount(2)
        ->and(Voucher::query()->whereIn('id', $approved->voucher_ids)->get()->every(fn (Voucher $voucher): bool => $voucher->isPosted()))->toBeTrue()
        ->and(VoucherAttachment::query()->where('voucher_id', $approved->voucher_ids[1])->where('sha256', $message->eml_sha256)->exists())->toBeTrue();
    InboxClassifierAgent::assertNeverPrompted();
});

it('reads a Peppol invoice into a supplier invoice, matched on the organisation number, and registers it', function () {
    $supplier = grossisten();
    Inbox::allow($this->company, 'faktura@grossisten.example');

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('peppol.eml')));
    $invoice = $message->proposal['invoice'];

    expect($message->status)->toBe(MessageStatus::DraftReady)
        ->and($message->interpreted_by)->toBe(Interpreter::Peppol)
        ->and($message->warnings)->toBe([])
        ->and($invoice['supplier_id'])->toBe($supplier->id)
        ->and($invoice['invoice_number'])->toBe('F-10045')
        ->and($invoice['ocr'])->toBe('100453')
        ->and($invoice['rows'])->toHaveCount(2)
        // The invoice image embedded in the XML is kept as an attachment and becomes the invoice file.
        ->and($message->attachments)->toHaveCount(2)
        ->and($message->proposal['file_attachment_id'])->toBe($message->attachments->firstWhere('mime', 'application/pdf')?->id);

    $approved = app(ApproveInboxMessage::class)($this->owner, $message, $message->proposal);
    $registered = SupplierInvoice::query()->findOrFail($approved->supplier_invoice_id);

    expect($registered->isDraft())->toBeFalse()
        ->and($registered->total_ore->ore)->toBe(181_000)
        ->and($registered->vat_ore->ore)->toBe(31_000)
        ->and($registered->file_mime)->toBe('application/pdf')
        ->and($approved->voucher_ids)->toBe([$registered->voucher_id])
        ->and(VoucherAttachment::query()->where('voucher_id', $registered->voucher_id)->where('sha256', $message->eml_sha256)->exists())->toBeTrue()
        ->and(InboxTemplate::query()->where('kind', InboxTemplate::SUPPLIER_INVOICE)->value('mapping'))->toEqual(['accounts_by_rate' => ['25' => 4010, '12' => 4010]]);
});

it('scans the invoice image embedded in a Peppol file like any attachment, and never shows a blocked one', function () {
    $scanned = [];
    app()->instance(VirusScanner::class, new class($scanned) implements VirusScanner
    {
        public function __construct(private array &$scanned) {}

        public function enabled(): bool
        {
            return true;
        }

        public function scan(string $contents): ?string
        {
            $this->scanned[] = substr($contents, 0, 5);

            return str_starts_with($contents, '%PDF') ? 'Viruskontrollen hittade Eicar-Test-Signature.' : null;
        }
    });
    grossisten();
    Inbox::allow($this->company, 'faktura@grossisten.example');

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('peppol.eml')));
    $image = $message->attachments->firstWhere('mime', 'application/pdf');

    expect($scanned)->toContain('%PDF-')
        ->and($image?->blocked_reason)->toBe('Viruskontrollen hittade Eicar-Test-Signature.')
        ->and($message->proposal['file_attachment_id'])->not->toBe($image?->id)
        ->and(collect($message->warnings)->pluck('code')->all())->toContain('BLOCKED_ATTACHMENT');

    $this->get(route('inbox.attachment', ['message' => $message->id, 'attachment' => $image?->id]))->assertNotFound();
});

it('blocks a dangerous file embedded in a Peppol file even without a virus scanner', function () {
    grossisten();
    Inbox::allow($this->company, 'faktura@grossisten.example');
    $xml = str_replace('filename="F-10045.pdf"', 'filename="faktura.exe"', Inbox::fixture('faktura-peppol.xml'));

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('faktura@grossisten.example', 'Faktura', 'Se bifogad faktura.', [['faktura.xml', $xml, 'application/xml']])));
    $image = $message->attachments->firstWhere('mime', 'application/pdf');

    expect($image?->filename)->toBe('faktura.exe')
        ->and($image?->isBlocked())->toBeTrue()
        ->and(collect($message->warnings)->pluck('code')->all())->toContain('BLOCKED_ATTACHMENT');
});

it('warns about a changed bankgiro at a known supplier and never books it automatically', function () {
    grossisten();
    Inbox::allow($this->company, 'faktura@grossisten.example');
    $first = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('peppol.eml')));
    app(ApproveInboxMessage::class)($this->owner, $first, $first->proposal);
    app(ManageInbox::class)->setAutoBook($this->owner, $this->company, InboxTemplate::query()->firstOrFail()->id, true);

    $xml = str_replace(['<cbc:ID>7654320</cbc:ID>', 'F-10045'], ['<cbc:ID>1234566</cbc:ID>', 'F-10046'], Inbox::fixture('faktura-peppol.xml'));
    $changed = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('faktura@grossisten.example', 'Faktura F-10046', 'Ny faktura.', [['faktura.xml', $xml, 'application/xml']])));

    expect($changed->status)->toBe(MessageStatus::NeedsReview)
        ->and($changed->interpreted_by)->toBe(Interpreter::Template)
        ->and(array_column($changed->warnings, 'code'))->toContain('BANK_DETAILS_CHANGED')
        ->and($changed->warnings[0]['message'])->toContain('1234566')
        ->and($changed->supplier_invoice_id)->toBeNull()
        ->and(SupplierInvoice::query()->count())->toBe(1);

    // A person must confirm the warning before approving.
    expect(fn () => app(ApproveInboxMessage::class)($this->owner, $changed, $changed->proposal))->toThrow(ValidationException::class);
});

it('warns when the same attachment arrives twice', function () {
    grossisten();
    Inbox::allow($this->company, 'faktura@grossisten.example');
    Inbox::receive($this->company, Inbox::fixture('peppol.eml'));

    $again = Inbox::fresh(Inbox::receive($this->company, str_replace('Message-ID: <pp-1@', 'Message-ID: <pp-2@', Inbox::fixture('peppol.eml'))));

    expect($again->status)->toBe(MessageStatus::NeedsReview)
        ->and(array_column($again->warnings, 'code'))->toContain('DUPLICATE_ATTACHMENT');
});

it('takes the exact same bytes only once', function () {
    Inbox::allow($this->company, 'faktura@grossisten.example');
    $first = Inbox::receive($this->company, Inbox::fixture('peppol.eml'));
    $second = Inbox::receive($this->company, Inbox::fixture('peppol.eml'));

    expect($second?->id)->toBe($first?->id)
        ->and(InboxMessage::query()->count())->toBe(1);
});

it('asks for the supplier when the Peppol invoice is from an unknown one', function () {
    Inbox::allow($this->company, 'faktura@grossisten.example');

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('peppol.eml')));

    expect($message->status)->toBe(MessageStatus::NeedsReview)
        ->and(array_column($message->warnings, 'code'))->toContain('NO_SUPPLIER')
        ->and($message->proposal['invoice']['supplier_name'])->toBe('Grossisten Exempel AB');
});

it('puts a PDF in the manual queue with AI off and shows the attachment', function () {
    Inbox::allow($this->company, '@exempelbolaget.example');

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('kvitto.eml'), 'kvitto'));
    $attachment = $message->attachments->first();

    expect($message->status)->toBe(MessageStatus::NeedsReview)
        ->and($message->interpreted_by)->toBe(Interpreter::Manual)
        ->and($message->document_type)->toBe(DocumentType::Receipt)
        ->and($message->proposal)->toBeNull();

    $this->get(route('inbox.attachment', ['message' => $message->id, 'attachment' => $attachment->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline; filename=kvitto.pdf');

    $this->get(route('inbox.original', ['message' => $message->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream');

    // A person writes the voucher by hand next to the attachment.
    $approved = app(ApproveInboxMessage::class)($this->owner, $message, ['kind' => 'vouchers', 'vouchers' => [[
        'date' => '2026-10-29', 'description' => 'Taxi', 'series' => 'A',
        'rows' => [['account' => 5800, 'debit_ore' => 20_000, 'credit_ore' => 0], ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => 20_000]],
    ]]]);

    expect(VoucherAttachment::query()->where('voucher_id', $approved->voucher_ids[0])->pluck('original_name')->all())->toContain('kvitto.pdf');
    InboxClassifierAgent::assertNeverPrompted();
});

it('serves anything that is not a PDF or picture only as a download', function () {
    Inbox::allow($this->company, 'kassa@exempel.example');
    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('kassa@exempel.example', 'Rapport', 'Hej', [['rapport.csv', "a;b\n1;2\n", 'text/csv']])));

    $this->get(route('inbox.attachment', ['message' => $message->id, 'attachment' => $message->attachments->first()->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Disposition', 'attachment; filename=rapport.csv');
});

it('serves the original e-mail linked to a voucher only as a download', function () {
    Inbox::allow($this->company, 'export@kassaexport.example');
    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('sie.eml')));
    $approved = app(ApproveInboxMessage::class)($this->owner, $message, $message->proposal);
    $voucher = Voucher::query()->findOrFail($approved->voucher_ids[0]);
    $eml = VoucherAttachment::query()->where('voucher_id', $voucher->id)->where('mime', 'message/rfc822')->sole();

    expect($eml->original_name)->toBe('SIE-fil oktober.eml');

    $this->get(route('accounting.vouchers.attachment', [$voucher, $eml->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
