<?php

declare(strict_types=1);

use App\Actions\Payables\SaveSupplier;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use App\Modules\Ai\Agents\SupplierInvoiceAgent;
use App\Modules\Ai\Agents\VoucherAgent;
use App\Modules\Inbox\Actions\AcceptInboundMail;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Modules\Inbox\Actions\InboxReview;
use App\Modules\Inbox\Actions\ManageInbox;
use App\Modules\Inbox\Actions\ReplyToSender;
use App\Modules\Inbox\Agents\DailyReportAgent;
use App\Modules\Inbox\Agents\InboxClassifierAgent;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\DocumentType;
use App\Modules\Inbox\Domain\Interpreter;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Livewire\InboxSettingsForm;
use App\Modules\Inbox\Livewire\InboxWorkspace;
use App\Modules\Inbox\Mail\InboxActionNeededMail;
use App\Modules\Inbox\Mail\InboxReplyMail;
use App\Modules\Inbox\Mail\InboxSummaryMail;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Policies\InboxMessagePolicy;
use App\Modules\ModuleManager;
use App\Support\Currency\RiksbankRates;
use App\Support\MailQuota;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;

/*
 * The inbox page: tabs, the proposal beside the attachment, approving with
 * a confirmation, rejecting, replying, claiming, and the notifications.
 */

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00:00'));
    [$this->owner, $this->company] = Inbox::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    InboxClassifierAgent::fake();
    DailyReportAgent::fake();
});

it('shows the tabs, opens a message from the query string and posts it after a confirmation', function () {
    Inbox::allow($this->company, 'export@kassaexport.example');
    $message = Inbox::receive($this->company, Inbox::fixture('sie.eml'));

    $component = Livewire::withQueryParams(['flik' => 'utkast-klara', 'id' => $message?->id])
        ->test(InboxWorkspace::class)
        ->assertSee('Utkast klara')
        ->assertSet('form.vouchers.0.description', 'Dagskassa 30 oktober (SIE A1)')
        ->call('approve', 'post')
        ->assertSet('confirmingApproval', true)
        ->assertSee('En bokförd verifikation kan aldrig ändras, bara rättas.');

    expect(Voucher::query()->count())->toBe(0);

    $component->call('approve', 'post')->assertDispatched('toast', message: '2 verifikationer är bokförda med e-posten som underlag.');

    expect(Voucher::query()->where('status', 'POSTED')->count())->toBe(2)
        ->and(Inbox::fresh($message)->status)->toBe(MessageStatus::Booked);

    Livewire::withQueryParams(['flik' => 'bokfort'])->test(InboxWorkspace::class)->assertSee('export@kassaexport.example');
});

it('lets a person write a voucher by hand next to an attachment in the manual queue', function () {
    Inbox::allow($this->company, '@exempelbolaget.example');
    $message = Inbox::receive($this->company, Inbox::fixture('kvitto.eml'));

    Livewire::test(InboxWorkspace::class)
        ->call('select', $message?->id)
        ->assertSee('Ingen tolkning finns.')
        ->assertSee('kvitto.pdf')
        ->call('startAs', 'vouchers')
        ->set('form.vouchers.0.rows.0.account', '5800')
        ->set('form.vouchers.0.rows.0.debit', '200,00')
        ->set('form.vouchers.0.rows.1.account', '1930')
        ->set('form.vouchers.0.rows.1.credit', '200,00')
        ->call('approve', 'draft')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Utkastet är skapat. Du hittar det bland verifikationerna eller leverantörsfakturorna.');

    expect(Inbox::fresh($message)->status)->toBe(MessageStatus::DraftCreated)
        ->and(Voucher::query()->sole()->isPosted())->toBeFalse();
});

it('creates the supplier of an invoice from the invoice and registers it', function () {
    Inbox::allow($this->company, 'faktura@grossisten.example');
    $message = Inbox::receive($this->company, Inbox::fixture('peppol.eml'));

    Livewire::test(InboxWorkspace::class)
        ->call('select', $message?->id)
        ->assertSee('Grossisten Exempel AB finns inte i leverantörsregistret.')
        ->call('createSupplier')
        ->assertDispatched('toast', message: 'Grossisten Exempel AB är tillagd i leverantörsregistret.')
        ->set('form.invoice.rows.0.account', '4010')
        ->set('form.invoice.rows.1.account', '4010')
        ->set('warningsConfirmed', true)
        ->call('approve', 'post')
        ->call('approve', 'post')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Leverantörsfakturan är registrerad med e-posten som underlag.');

    expect(Supplier::query()->sole()->bankgiro)->not->toBeNull()
        ->and(Inbox::fresh($message)->supplier_invoice_id)->not->toBeNull();
});

it('rejects a message after a confirmation and lists it under Avvisat', function () {
    Inbox::allow($this->company, 'export@kassaexport.example');
    $message = Inbox::receive($this->company, Inbox::fixture('sie.eml'));

    Livewire::test(InboxWorkspace::class)
        ->call('select', $message?->id)
        ->call('reject')
        ->set('rejectionReason', 'Fel företag')
        ->call('reject')
        ->assertDispatched('toast', message: 'Underlaget är avvisat. Det bokförs inte.');

    expect(Inbox::fresh($message)->status)->toBe(MessageStatus::Rejected)
        ->and(Inbox::fresh($message)->rejection_reason)->toBe('Fel företag');
    Livewire::withQueryParams(['flik' => 'avvisat'])->test(InboxWorkspace::class)->assertSee('export@kassaexport.example');
});

it('allows a quarantined sender from the page', function () {
    $message = Inbox::receive($this->company, Inbox::fixture('sie.eml'));

    Livewire::test(InboxWorkspace::class)
        ->call('select', $message?->id)
        ->assertSee('finns inte på listan över godkända avsändare')
        ->call('allowSender')
        ->assertDispatched('toast', message: 'export@kassaexport.example är godkänd. 1 meddelanden tolkas nu.');

    expect(Inbox::fresh($message)->status)->toBe(MessageStatus::DraftReady);
});

it('answers the sender through the mailer, within the mail quota, and logs it', function () {
    Inbox::allow($this->company, 'export@kassaexport.example');
    $message = Inbox::receive($this->company, Inbox::fixture('sie.eml'));

    Livewire::test(InboxWorkspace::class)
        ->call('select', $message?->id)
        ->set('replyText', 'Kvittot saknas, kan du skicka det?')
        ->call('reply')
        ->assertDispatched('toast', message: 'Svaret är skickat till export@kassaexport.example.');

    Mail::assertSent(InboxReplyMail::class, fn (InboxReplyMail $mail): bool => $mail->hasTo('export@kassaexport.example') && $mail->text === 'Kvittot saknas, kan du skicka det?');
    expect(AuditLog::query()->where('action', 'inbox.replied')->sole()->payload['to'])->toBe('export@kassaexport.example');

    foreach (range(1, MailQuota::LIMITS['documents']) as $ignored) {
        RateLimiter::hit("mail-quota:documents:{$this->company->id}", 3600);
    }

    expect(fn () => app(ReplyToSender::class)($this->owner, Inbox::fresh($message), 'Igen'))->toThrow(ValidationException::class);
});

it('never answers a sender who is not allowed or whose mail failed authentication', function () {
    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('sie.eml')));

    expect(fn () => app(ReplyToSender::class)($this->owner, $message, 'Hej'))->toThrow(ValidationException::class);
    Mail::assertNothingSent();
});

it('reads a PDF invoice and a receipt with NONOX AI when it is on, without anyone behind the call', function () {
    Inbox::enableAi($this->company, $this->owner);
    app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten Exempel AB', 'org_number' => '559900-1236', 'bankgiro' => '765-4320', 'payment_terms_days' => 30, 'default_account' => 4010]);
    Inbox::allow($this->company, '@exempelbolaget.example');
    InboxClassifierAgent::fake([['document_type' => 'SUPPLIER_INVOICE', 'reason' => 'Har förfallodag och bankgiro.', 'confidence' => 0.95]]);
    SupplierInvoiceAgent::fake([[
        'supplier_name' => 'Grossisten Exempel AB', 'supplier_org_number' => '559900-1236', 'supplier_vat_number' => '', 'supplier_bankgiro' => '123-4566', 'supplier_plusgiro' => '', 'supplier_iban' => '',
        'supplier_address' => '', 'supplier_postal_code' => '', 'supplier_city' => '', 'supplier_email' => '', 'supplier_phone' => '',
        'invoice_number' => '7001', 'ocr' => '7001233', 'invoice_date' => '2026-10-20', 'due_date' => '2026-11-19', 'currency' => 'SEK',
        'total' => 1250.0, 'vat_total' => 250.0, 'is_credit_note' => false, 'vat_treatment' => 'DOMESTIC', 'description' => 'Kaffe',
        'rows' => [['account' => 4010, 'amount_excl_vat' => 1000.0, 'vat_rate_percent' => 25, 'description' => 'Kaffe']], 'notes' => '',
    ]]);
    VoucherAgent::fake([['document_type' => 'RECEIPT', 'date' => '2026-10-29', 'description' => 'Taxi', 'counterparty' => 'Taxi', 'total' => 200.0, 'vat_total' => 10.71,
        'rows' => [['account' => 5800, 'debit' => 200.0, 'credit' => 0, 'description' => ''], ['account' => 1930, 'debit' => 0, 'credit' => 200.0, 'description' => '']], 'notes' => '']]);

    $invoice = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('kvitto.eml')));
    $receipt = Inbox::fresh(Inbox::receive($this->company, str_replace('kv-1@', 'kv-2@', Inbox::fixture('kvitto.eml')), 'kvitto'));

    expect($invoice->document_type)->toBe(DocumentType::SupplierInvoice)
        ->and($invoice->interpreted_by)->toBe(Interpreter::Ai)
        ->and($invoice->proposal['invoice']['invoice_number'])->toBe('7001')
        ->and(array_column($invoice->warnings, 'code'))->toContain('BANK_DETAILS_CHANGED')
        ->and($receipt->document_type)->toBe(DocumentType::Receipt)
        ->and($receipt->proposal['vouchers'][0]['rows'][0])->toBe(['account' => 5800, 'debit_ore' => 20_000, 'credit_ore' => 0, 'description' => '']);

    InboxClassifierAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->first() instanceof Document);
    // The +kvitto alias is a hint enough: the receipt was not classified again.
    InboxClassifierAgent::assertPromptedTimes(1);
    expect(AuditLog::query()->where('action', 'ai.document_analyzed')->count())->toBe(3);
});

it('tells the inbox handlers by e-mail at most once an hour, and sends the daily summary once a day', function () {
    $modules = app(ModuleManager::class);
    $modules->updateSettings(InboxModule::KEY, $this->company, $this->owner, ['notify_on_action' => true, 'daily_summary' => true]);
    $auditor = Bookkeeping::member($this->company, 'auditor');

    Inbox::receive($this->company, Inbox::fixture('sie.eml'));
    Inbox::receive($this->company, Inbox::fixture('kvitto.eml'));

    Mail::assertSent(InboxActionNeededMail::class, 1);
    Mail::assertSent(InboxActionNeededMail::class, fn (InboxActionNeededMail $mail): bool => $mail->hasTo($this->owner->email));
    Mail::assertNotSent(InboxActionNeededMail::class, fn (InboxActionNeededMail $mail): bool => $mail->hasTo($auditor->email));

    $this->artisan('inbox:daily-summary')->assertSuccessful();
    $this->artisan('inbox:daily-summary')->assertSuccessful();

    Mail::assertSent(InboxSummaryMail::class, 1);
    Mail::assertSent(InboxSummaryMail::class, fn (InboxSummaryMail $mail): bool => $mail->counts['review'] === 2 && $mail->counts['received'] === 2);
});

it('removes rejected mail after 7 days, unhandled quarantine after 90, but never mail that led to bookkeeping', function () {
    Inbox::allow($this->company, 'export@kassaexport.example');
    $rejected = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('sie.eml')));
    app(InboxReview::class)->reject($this->owner, $rejected);
    $booked = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('peppol.eml')));
    $booked->forceFill(['status' => MessageStatus::Rejected, 'handled_at' => now(), 'voucher_ids' => ['00000000-0000-0000-0000-000000000000']])->save();

    $quarantined = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('z-kassanova.eml')));
    expect($quarantined->status)->toBe(MessageStatus::Quarantine);

    $this->travel(8)->days();
    $this->artisan('inbox:prune')->assertSuccessful();

    expect(InboxMessage::query()->orderBy('received_at')->pluck('id')->sort()->values()->all())->toBe(collect([$booked->id, $quarantined->id])->sort()->values()->all())
        ->and(Storage::disk('local')->exists($rejected->eml_path))->toBeFalse();

    $this->travel(83)->days();
    $this->artisan('inbox:prune')->assertSuccessful();

    expect(InboxMessage::query()->pluck('id')->all())->toBe([$booked->id]);
});

it('never stores spam from senders outside the allowlist, but keeps an allowed sender\'s flagged mail', function () {
    $spam = app(AcceptInboundMail::class)($this->company, Inbox::fixture('z-kassanova.eml'), Channel::Mailgun, 'exempel-abc12345@in.nonox.app', null, ['X-Mailgun-Sflag' => 'Yes']);

    expect($spam)->toBeNull()
        ->and(InboxMessage::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'inbox.dropped')->latest('id')->value('payload'))->toMatchArray(['reason' => 'spam']);

    Inbox::allow($this->company, 'rapport@kassanova.example');
    $kept = app(AcceptInboundMail::class)($this->company, Inbox::fixture('z-kassanova.eml'), Channel::Mailgun, 'exempel-abc12345@in.nonox.app', null, ['X-Mailgun-Sflag' => 'Yes']);

    expect($kept?->status)->toBe(MessageStatus::Quarantine)
        ->and(collect($kept?->quarantine_reasons)->pluck('code')->all())->toContain('SPAM');
});

describe('permissions', function () {
    it('gives each role what the role table says', function (string $role, bool $view, bool $manage, bool $settings) {
        $user = Bookkeeping::member($this->company, $role);

        expect($user->hasTeamPermission($this->company, 'inbox:view'))->toBe($view)
            ->and($user->hasTeamPermission($this->company, 'inbox:manage'))->toBe($manage)
            ->and($user->hasTeamPermission($this->company, 'inbox:settings'))->toBe($settings);
    })->with([
        'admin' => ['admin', true, true, true],
        'accountant' => ['accountant', true, true, true],
        'bookkeeper' => ['bookkeeper', true, true, false],
        'approver' => ['approver', false, false, false],
        'auditor' => ['auditor', true, false, false],
    ]);

    it('lets an auditor see the inbox but not act in it', function () {
        Inbox::allow($this->company, 'export@kassaexport.example');
        $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('sie.eml')));
        $auditor = Bookkeeping::member($this->company, 'auditor');
        $policy = new InboxMessagePolicy;

        expect($policy->view($auditor, $message))->toBeTrue()
            ->and($policy->manage($auditor, $message))->toBeFalse()
            ->and(fn () => app(ApproveInboxMessage::class)($auditor, $message, $message->proposal))->toThrow(AuthorizationException::class)
            ->and(fn () => app(InboxReview::class)->reject($auditor, $message))->toThrow(AuthorizationException::class);

        $this->actingAs($auditor)->get(route('inbox.index'))->assertOk();
        $this->actingAs($auditor)->get(route('inbox.original', ['message' => $message->id]))->assertOk();
    });

    it('lets a bookkeeper approve but not change the settings', function () {
        $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
        $this->actingAs($bookkeeper);

        Livewire::test(InboxSettingsForm::class, ['company' => $this->company])->assertForbidden();

        expect(fn () => app(ManageInbox::class)->allow($bookkeeper, $this->company, '@exempel.example'))->toThrow(AuthorizationException::class);
    });

    it('answers 404 for the inbox pages while the module is off', function () {
        app(ModuleManager::class)->disable(InboxModule::KEY, $this->company, $this->owner, true);

        $this->get('/inkorg')->assertNotFound();
    });
});

it('deletes a message that was not booked, with its files, and keeps one that was', function () {
    Inbox::allow($this->company, 'leverantor@exempel.example');
    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('leverantor@exempel.example', 'Faktura', 'Se bilagan.', [['faktura.pdf', Inbox::fixture('faktura.pdf'), 'application/pdf']])));
    $files = [$message->eml_path, ...$message->attachments->pluck('path')->all()];

    Livewire::test(InboxWorkspace::class)
        ->call('select', $message->id)
        ->assertSee('Radera')
        ->call('delete')
        ->assertDispatched('toast', message: 'Mejlet och dess bilagor är raderade.');

    expect(InboxMessage::query()->find($message->id))->toBeNull()
        ->and(collect($files)->filter(fn (string $path): bool => Storage::disk('local')->exists($path)))->toBeEmpty()
        ->and(AuditLog::query()->where('action', 'inbox.deleted')->sole()->payload['message'])->toBe($message->id);

    Inbox::allow($this->company, 'export@kassaexport.example');
    $booked = Inbox::receive($this->company, Inbox::fixture('sie.eml'));
    $booked = app(ApproveInboxMessage::class)($this->owner, $booked, $booked->proposal);

    expect(fn () => app(InboxReview::class)->delete($this->owner, $booked))->toThrow(ValidationException::class, 'Underlaget är bokfört');
    expect(InboxMessage::query()->find($booked->id))->not->toBeNull();
});

it('reads a message again on request', function () {
    Inbox::allow($this->company, 'leverantor@exempel.example');
    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('leverantor@exempel.example', 'Faktura', 'Se bilagan.', [['faktura.pdf', Inbox::fixture('faktura.pdf'), 'application/pdf']])));

    Livewire::test(InboxWorkspace::class)
        ->call('select', $message->id)
        ->call('interpretAgain')
        ->assertDispatched('toast');

    expect(AuditLog::query()->where('action', 'inbox.interpret_again')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'inbox.interpreted')->where('subject_id', $message->id)->count())->toBe(2);
});

/** NONOX AI's reading of a supplier invoice in US dollars. */
function dollarInvoice(): array
{
    return [
        'supplier_name' => 'Molnet Exempel Inc.', 'supplier_org_number' => '', 'supplier_vat_number' => '', 'supplier_bankgiro' => '', 'supplier_plusgiro' => '', 'supplier_iban' => '',
        'supplier_address' => '', 'supplier_postal_code' => '', 'supplier_city' => '', 'supplier_email' => '', 'supplier_phone' => '',
        'invoice_number' => 'INV-42', 'ocr' => '', 'invoice_date' => '2026-10-30', 'due_date' => '2026-11-15', 'currency' => 'USD',
        'total' => 1517.5, 'vat_total' => 0.0, 'is_credit_note' => false, 'vat_treatment' => 'REVERSE_CHARGE', 'description' => 'Servrar oktober',
        'rows' => [['account' => 6540, 'amount_excl_vat' => 1517.5, 'vat_rate_percent' => 0, 'description' => 'Servrar']], 'notes' => '',
    ];
}

it('converts an invoice in dollars to kronor with Riksbanken\'s rate and keeps the dollar amounts', function () {
    Inbox::enableAi($this->company, $this->owner);
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Molnet Exempel Inc.', 'payment_terms_days' => 14, 'default_account' => 6540]);
    Inbox::allow($this->company, '@exempelbolaget.example');
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-10-29', 'value' => 9.88275], ['date' => '2026-10-30', 'value' => 9.82635]])]);
    SupplierInvoiceAgent::fake([[...dollarInvoice(), 'supplier_id' => $supplier->id]]);

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('kvitto.eml'), 'faktura'));
    $invoice = $message->proposal['invoice'];

    expect($invoice['currency'])->toBe('USD')
        ->and($invoice['foreign']['total'])->toBe(Money::ofOre(151_750)->format(withCurrency: false))
        ->and($invoice['exchange_rate'])->toBe('9,826350')
        ->and($invoice['rate_date'])->toBe('2026-10-30')
        ->and($invoice['total'])->toBe(Money::ofOre(1_491_149)->format(withCurrency: false))
        ->and($invoice['rows'][0]['amount'])->toBe(Money::ofOre(1_491_149)->format(withCurrency: false))
        ->and(array_column($message->warnings, 'code'))->toContain('CURRENCY');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'Observations/SEKUSDPMI/2026-10-20/2026-10-30'));

    // The same day again: the rate is cached, Riksbanken is not asked twice.
    expect(app(RiksbankRates::class)->rate('USD', '2026-10-30')?->micros)->toBe(9_826_350);
    Http::assertSentCount(1);

    Livewire::test(InboxWorkspace::class)
        ->call('select', $message->id)
        ->assertSee('Fakturan är i USD')
        ->set('form.invoice.exchange_rate', '10')
        ->assertSet('form.invoice.total', Money::ofOre(1_517_500)->format(withCurrency: false))
        ->assertSet('form.invoice.rows.0.amount', Money::ofOre(1_517_500)->format(withCurrency: false))
        ->set('form.invoice.supplier_id', $supplier->id)
        ->set('warningsConfirmed', true)
        ->call('approve', 'draft')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $registered = SupplierInvoice::query()->findOrFail(Inbox::fresh($message)->supplier_invoice_id);
    expect($registered->total_ore->ore)->toBe(1_517_500)
        ->and($registered->description)->toContain('('.Money::ofOre(151_750)->format(withCurrency: false).' USD, kurs 10,000000)');
});

it('asks for the rate when Riksbanken has none, and refuses the invoice without it', function () {
    Inbox::enableAi($this->company, $this->owner);
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Molnet Exempel Inc.', 'payment_terms_days' => 14, 'default_account' => 6540]);
    Inbox::allow($this->company, '@exempelbolaget.example');
    Http::fake(['api.riksbank.se/*' => Http::response(['statusCode' => 429], 429)]);
    SupplierInvoiceAgent::fake([[...dollarInvoice(), 'supplier_id' => $supplier->id]]);

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('kvitto.eml'), 'faktura'));

    expect($message->proposal['invoice']['total'])->toBe('')
        ->and(array_column($message->warnings, 'code'))->toContain('CURRENCY_RATE');

    expect(fn () => app(ApproveInboxMessage::class)($this->owner, $message, $message->proposal, ApproveInboxMessage::DRAFT, true))
        ->toThrow(ValidationException::class, 'Skriv kursen i kronor per USD');
});
