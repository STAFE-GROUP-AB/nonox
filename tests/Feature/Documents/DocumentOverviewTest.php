<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentOverview;
use App\Livewire\Documents\DocumentOverviewPage;
use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Ai\AiModule;
use App\Modules\Boardroom\BoardroomModule;
use App\Modules\Crm\CrmModule;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentSupport;
use Tests\Support\Bookkeeping;
use Tests\Support\DimensionScopeFixture;
use Tests\Support\Portal;

/*
 * ADR-081 gap 9, E20: one list over the company's documents and the files
 * of the feature sources. Every adapter applies the permission check of its
 * own source, with one test per adapter for a role that may not see it, and
 * the list runs a fixed number of queries.
 */

/**
 * One file per source for the company, with real parent rows (no foreign key is switched off, so the file also
 * runs as the application's own database role). The rows are inserted straight into the tables because the point
 * is what the list shows, not how a feature creates its rows; the parents they need are inserted first. Every
 * title carries its source's own word, so a test can look for it in the page.
 *
 * @return array<string, string> source key => the title to look for, and _voucher / _message, the parents to add files to
 */
function e20Seed(Company $company, User $owner, int $copies = 1): array
{
    $modules = app(ModuleManager::class);
    $modules->enable(PayrollModule::KEY, $company, $owner);
    $modules->enable(AiModule::KEY, $company, $owner);
    $modules->enable(AgreementsModule::KEY, $company, $owner);
    $modules->enable(CrmModule::KEY, $company, $owner);
    $modules->enable(InboxModule::KEY, $company, $owner);
    $modules->enable(BoardroomModule::KEY, $company, $owner);
    Portal::enable($company, $owner);
    CurrentCompany::set($company);

    $voucher = Bookkeeping::post($owner, $company);
    $now = now()->toDateTimeString();
    $sha = str_repeat('a', 64);
    $id = $company->id;
    $messageId = (string) Str::uuid();

    $supplier = DB::table('suppliers')->insertGetId(['company_id' => $id, 'number' => 1, 'name' => 'Leverantören AB', 'created_at' => $now, 'updated_at' => $now]);
    $customer = DB::table('customers')->insertGetId(['company_id' => $id, 'number' => 1, 'name' => 'Kunden AB', 'created_at' => $now, 'updated_at' => $now]);
    $account = DB::table('crm_accounts')->insertGetId(['company_id' => $id, 'name' => 'Kunden AB', 'created_at' => $now, 'updated_at' => $now]);
    $agreement = DB::table('crm_agreements')->insertGetId(['company_id' => $id, 'account_id' => $account, 'title' => 'Ramavtal', 'start_date' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now]);
    $quote = (string) Str::uuid();
    DB::table('sales_documents')->insert(['id' => $quote, 'company_id' => $id, 'kind' => 'QUOTE', 'number' => 7, 'customer_id' => $customer, 'document_date' => '2026-03-01', 'created_at' => $now, 'updated_at' => $now]);
    $thread = DB::table('customer_portal_threads')->insertGetId(['company_id' => $id, 'customer_id' => $customer, 'subject' => 'Fråga om faktura', 'last_message_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $message = DB::table('customer_portal_messages')->insertGetId(['company_id' => $id, 'thread_id' => $thread, 'author_kind' => 'CONTACT', 'body' => 'Hej', 'created_at' => $now]);
    $meeting = DB::table('boardroom_meetings')->insertGetId(['company_id' => $id, 'meeting_date' => '2026-03-01', 'title' => 'Styrelsemöte', 'kind' => 'BOARD', 'created_at' => $now, 'updated_at' => $now]);
    $employee = DB::table('employees')->insertGetId(['company_id' => $id, 'employee_number' => 1, 'first_name' => 'Anna', 'last_name' => 'Test', 'birth_date' => '1990-01-01', 'created_at' => $now, 'updated_at' => $now]);
    $employment = DB::table('employments')->insertGetId(['company_id' => $id, 'employee_id' => $employee, 'start_date' => '2025-01-01', 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY', 'created_at' => $now, 'updated_at' => $now]);
    $run = DB::table('payroll_runs')->insertGetId(['company_id' => $id, 'period' => '202601', 'payment_date' => '2026-01-25', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('payslips')->insert(['company_id' => $id, 'payroll_run_id' => $run, 'employee_id' => $employee, 'employment_id' => $employment, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('inbox_messages')->insert(['id' => $messageId, 'company_id' => $id, 'channel' => 'EMAIL', 'recipient' => 'a@example.test', 'from_email' => 'b@example.test', 'received_at' => $now, 'eml_path' => 'e20/eml', 'eml_sha256' => $sha, 'eml_size' => 10, 'status' => 'RECEIVED', 'auth' => '{}', 'warnings' => '[]', 'voucher_ids' => '[]', 'quarantine_reasons' => '[]', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('inbox_attachments')->insert(['company_id' => $id, 'inbox_message_id' => $messageId, 'position' => 99, 'filename' => 'blockerad-e20.exe', 'mime' => 'application/octet-stream', 'size' => 1, 'sha256' => $sha, 'path' => 'e20/blocked', 'blocked_reason' => 'EXECUTABLE', 'created_at' => $now, 'updated_at' => $now]);

    for ($i = 1; $i <= $copies; $i++) {
        DB::table('voucher_attachments')->insert(['voucher_id' => $voucher->id, 'company_id' => $id, 'file_path' => "e20/v{$i}", 'original_name' => "underlag-e20-{$i}.pdf", 'mime' => 'application/pdf', 'size' => 1000, 'sha256' => $sha, 'uploaded_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('supplier_invoices')->insert(['id' => (string) Str::uuid(), 'supplier_id' => $supplier, 'company_id' => $id, 'invoice_date' => '2026-03-01', 'due_date' => '2026-04-01', 'file_path' => "e20/s{$i}", 'file_name' => "faktura-e20-{$i}.pdf", 'file_mime' => 'application/pdf', 'file_size' => 2000, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('inbox_attachments')->insert(['company_id' => $id, 'inbox_message_id' => $messageId, 'position' => $i, 'filename' => "inkorg-e20-{$i}.pdf", 'mime' => 'application/pdf', 'size' => 3000, 'sha256' => $sha, 'path' => "e20/i{$i}", 'created_at' => $now, 'updated_at' => $now]);
        DB::table('agreement_documents')->insert(['company_id' => $id, 'document_type' => 'HANGAVTAL', 'original_name' => Crypt::encryptString("avtal-e20-{$i}.pdf"), 'file_path' => "e20/a{$i}", 'mime' => 'application/pdf', 'size' => 4000, 'sha256' => $sha, 'uploaded_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('crm_signing_requests')->insert(['company_id' => $id, 'document_type' => 'AGREEMENT', 'agreement_id' => $agreement, 'token_hash' => str_repeat('b', 64), 'email' => 'k@example.test', 'expires_at' => '2030-01-01', 'document_path' => "e20/g{$i}", 'document_name' => "signering-e20-{$i}.pdf", 'document_sha256' => $sha, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('crm_signing_requests')->insert(['company_id' => $id, 'document_type' => 'QUOTE', 'sales_document_id' => $quote, 'token_hash' => str_repeat('d', 64), 'email' => 'k@example.test', 'expires_at' => '2030-01-01', 'document_path' => "e20/q{$i}", 'document_name' => "offertsignering-e20-{$i}.pdf", 'document_sha256' => $sha, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('customer_portal_attachments')->insert(['company_id' => $id, 'message_id' => $message, 'path' => "e20/p{$i}", 'original_name' => "portal-e20-{$i}.pdf", 'mime' => 'application/pdf', 'size' => 5000, 'sha256' => $sha, 'created_at' => $now]);
        DB::table('boardroom_minutes')->insert(['company_id' => $id, 'meeting_id' => $meeting, 'body' => '{"items":[]}', 'status' => 'APPROVED', 'pdf_path' => "e20/b{$i}", 'sha256' => $sha, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('imported_payslips')->insert(['company_id' => $id, 'employee_id' => $employee, 'period' => '202601', 'file_path' => "e20/l{$i}", 'original_name' => "lonebesked-e20-{$i}.pdf", 'sha256' => $sha, 'uploaded_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
    }

    DocumentSupport::store($owner, $company, ['title' => 'Dokument-e20']);

    return [
        'document' => 'Dokument-e20', 'voucher' => 'underlag-e20-1.pdf', 'supplier-invoice' => 'faktura-e20-1.pdf',
        'inbox' => 'inkorg-e20-1.pdf', 'agreement' => 'avtal-e20-1.pdf', 'signing' => 'signering-e20-1.pdf',
        'portal' => 'portal-e20-1.pdf', 'boardroom' => 'protokoll-', 'staff' => 'lonebesked-e20-1.pdf',
        '_voucher' => (string) $voucher->id, '_message' => $messageId,
    ];
}

/** @return list<string> the source keys the user may read */
function e20Allowed(User $user, Company $company): array
{
    return array_map(fn ($source): string => $source->key(), DocumentOverview::allowed($user, $company));
}

beforeEach(function () {
    $this->root = DocumentSupport::useTemporaryDisk();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

afterEach(fn () => DocumentSupport::removeTemporaryDisk($this->root));

it('lists one row per source for a member who may read all of them', function () {
    $titles = e20Seed($this->company, $this->owner);
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    $this->actingAs($payrollAdmin);

    $page = DocumentOverview::page($payrollAdmin, $this->company, '', '', 50);

    // The payroll administrator reads the staff documents and the agreement documents of the owner's modules, not the rest.
    expect(collect($page->items())->pluck('source')->unique()->sort()->values()->all())->toBe(['staff']);

    $this->actingAs($this->owner);
    $all = DocumentOverview::page($this->owner, $this->company, '', '', 50);
    $keys = collect($all->items())->pluck('source')->unique()->sort()->values()->all();

    expect($keys)->toBe(['agreement', 'boardroom', 'document', 'inbox', 'portal', 'signing', 'supplier-invoice', 'voucher'])
        ->and(collect($all->items())->pluck('title')->all())->toContain($titles['voucher'], $titles['supplier-invoice'], $titles['inbox'], $titles['agreement'], $titles['signing'], $titles['portal'], 'Dokument-e20');
});

it('hides the vouchers\' underlag from a role without vouchers:view', function () {
    e20Seed($this->company, $this->owner);
    $member = Bookkeeping::member($this->company, 'payroll_admin');

    expect(e20Allowed($member, $this->company))->not->toContain('voucher')
        ->and(e20Allowed($this->owner, $this->company))->toContain('voucher')
        ->and(collect(DocumentOverview::page($member, $this->company, 'voucher', '', 50)->items()))->toBeEmpty();
});

it('hides supplier invoice files from a role without the payables permissions', function () {
    e20Seed($this->company, $this->owner);
    $member = Bookkeeping::member($this->company, 'payroll_admin');

    expect(e20Allowed($member, $this->company))->not->toContain('supplier-invoice')
        ->and(e20Allowed($this->owner, $this->company))->toContain('supplier-invoice')
        ->and(collect(DocumentOverview::page($member, $this->company, 'supplier-invoice', '', 50)->items()))->toBeEmpty();
});

it('hides the inbox attachments from a role without inbox:view', function () {
    e20Seed($this->company, $this->owner);
    $member = Bookkeeping::member($this->company, 'approver');

    expect(e20Allowed($member, $this->company))->not->toContain('inbox')
        ->and(e20Allowed($this->owner, $this->company))->toContain('inbox')
        ->and(collect(DocumentOverview::page($member, $this->company, 'inbox', '', 50)->items()))->toBeEmpty();
});

it('hides the NONOX Avtal documents from a role that may not manage agreements', function () {
    e20Seed($this->company, $this->owner);
    $member = Bookkeeping::member($this->company, 'accountant');

    expect(e20Allowed($member, $this->company))->not->toContain('agreement')
        ->and(e20Allowed($this->owner, $this->company))->toContain('agreement')
        ->and(collect(DocumentOverview::page($member, $this->company, 'agreement', '', 50)->items()))->toBeEmpty();
});

it('hides the signing files from a role with neither invoices:view nor crm:view', function () {
    e20Seed($this->company, $this->owner);
    $member = Bookkeeping::member($this->company, 'payroll_admin');

    expect(e20Allowed($member, $this->company))->not->toContain('signing')
        ->and(e20Allowed($this->owner, $this->company))->toContain('signing')
        ->and(collect(DocumentOverview::page($member, $this->company, 'signing', '', 50)->items()))->toBeEmpty();
});

it('shows a signing request of an agreement only with crm:view and of a quote only with invoices:view', function () {
    e20Seed($this->company, $this->owner);
    $sales = Bookkeeping::member($this->company, 'sales');

    // The seller holds crm:view, so the agreement's request is listed; the quote part adds nothing without a quote.
    expect(e20Allowed($sales, $this->company))->toContain('signing')
        ->and(collect(DocumentOverview::page($sales, $this->company, 'signing', '', 50)->items())->pluck('title')->all())->toContain('signering-e20-1.pdf');
});

it('hides the portal files from a role without customer-portal:view', function () {
    e20Seed($this->company, $this->owner);
    $member = Bookkeeping::member($this->company, 'approver');

    expect(e20Allowed($member, $this->company))->not->toContain('portal')
        ->and(e20Allowed($this->owner, $this->company))->toContain('portal')
        ->and(collect(DocumentOverview::page($member, $this->company, 'portal', '', 50)->items()))->toBeEmpty();
});

it('hides the minutes from a role without meetings:view', function () {
    e20Seed($this->company, $this->owner);
    $member = Bookkeeping::member($this->company, 'bookkeeper');

    expect(e20Allowed($member, $this->company))->not->toContain('boardroom')
        ->and(e20Allowed($this->owner, $this->company))->toContain('boardroom')
        ->and(collect(DocumentOverview::page($member, $this->company, 'boardroom', '', 50)->items()))->toBeEmpty();
});

it('lists staff documents only with payroll:view-sensitive, so not for the owner or the accountant', function () {
    e20Seed($this->company, $this->owner);
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');

    expect(e20Allowed($this->owner, $this->company))->not->toContain('staff')
        ->and(e20Allowed($accountant, $this->company))->not->toContain('staff')
        ->and(collect(DocumentOverview::page($accountant, $this->company, 'staff', '', 50)->items()))->toBeEmpty()
        ->and(e20Allowed($payrollAdmin, $this->company))->toContain('staff')
        ->and(collect(DocumentOverview::page($payrollAdmin, $this->company, 'staff', '', 50)->items())->pluck('title')->all())->toContain('lonebesked-e20-1.pdf');
});

it('hides the company documents from a role without documents:view', function () {
    e20Seed($this->company, $this->owner);
    $member = Bookkeeping::member($this->company, 'approver');

    expect(e20Allowed($member, $this->company))->not->toContain('document')
        ->and(e20Allowed($this->owner, $this->company))->toContain('document')
        ->and(collect(DocumentOverview::page($member, $this->company, 'document', '', 50)->items()))->toBeEmpty();
});

it('lists only the user\'s own company, never another company\'s files', function () {
    $titles = e20Seed($this->company, $this->owner);
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $this->actingAs($otherOwner);
    CurrentCompany::set($otherCompany);

    $rows = DocumentOverview::page($otherOwner, $otherCompany, '', '', 50);

    expect(collect($rows->items())->pluck('title')->all())->not->toContain($titles['voucher'])
        ->not->toContain($titles['inbox'])
        ->not->toContain('Dokument-e20');
});

it('answers 403 at /dokument/alla for a member who may read no source and shows the page to the others', function () {
    e20Seed($this->company, $this->owner);
    $this->get('/dokument/alla')->assertOk()->assertSee('Alla dokument')->assertSee('underlag-e20-1.pdf');

    $none = Bookkeeping::member($this->company, 'employee');
    Livewire::actingAs($none)->test(DocumentOverviewPage::class)->assertForbidden();
});

it('filters by source and searches the title, in the address', function () {
    e20Seed($this->company, $this->owner);

    Livewire::test(DocumentOverviewPage::class)
        ->set('source', 'voucher')
        ->assertSee('underlag-e20-1.pdf')
        ->assertDontSee('faktura-e20-1.pdf')
        ->set('source', '')
        ->set('search', 'faktura-e20')
        ->assertSee('faktura-e20-1.pdf')
        ->assertDontSee('underlag-e20-1.pdf');
});

it('shows an encrypted title decrypted and does not search it', function () {
    e20Seed($this->company, $this->owner);

    Livewire::test(DocumentOverviewPage::class)->set('source', 'agreement')->assertSee('avtal-e20-1.pdf')
        ->set('source', '')->set('search', 'avtal-e20')->assertDontSee('avtal-e20-1.pdf');
});

it('runs the same number of queries for one file per source and for many', function () {
    $titles = e20Seed($this->company, $this->owner, 1);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(DocumentOverviewPage::class)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $short = $count();
    // Fill the same sources with more files.
    $before = DB::table('voucher_attachments')->where('company_id', $this->company->id)->count();

    for ($i = 2; $i <= 15; $i++) {
        DB::table('voucher_attachments')->insert(['voucher_id' => $titles['_voucher'], 'company_id' => $this->company->id, 'file_path' => "e20/m{$i}", 'original_name' => "mer-{$i}.pdf", 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('c', 64), 'uploaded_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inbox_attachments')->insert(['company_id' => $this->company->id, 'inbox_message_id' => $titles['_message'], 'position' => $i, 'filename' => "mer-inkorg-{$i}.pdf", 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('c', 64), 'path' => "e20/mi{$i}", 'created_at' => now(), 'updated_at' => now()]);
    }

    expect(DB::table('voucher_attachments')->where('company_id', $this->company->id)->count())->toBeGreaterThan($before)
        ->and($count())->toBe($short);
});

it('keeps every adapter inside its own company', function () {
    e20Seed($this->company, $this->owner);
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $this->actingAs($otherOwner);
    CurrentCompany::set($otherCompany);

    foreach (DocumentOverview::sources() as $source) {
        $mine = CurrentCompany::run($this->company, fn (): int => $source->query($this->owner, $this->company, '')->count());
        $theirs = CurrentCompany::run($otherCompany, fn (): int => $source->query($otherOwner, $otherCompany, '')->count());

        expect($mine)->toBeGreaterThan(0, "{$source->key()} listed nothing for the company that has files")
            ->and($theirs)->toBe(0, "{$source->key()} showed another company's files");
    }
});

it('refuses the voucher and supplier invoice sources and the page to a member limited to cost centres or projects', function () {
    $fixture = DimensionScopeFixture::make();
    $limited = $fixture->member();
    CurrentCompany::set($fixture->company);
    $this->actingAs($limited);

    expect(e20Allowed($limited, $fixture->company))->not->toContain('voucher')->not->toContain('supplier-invoice');

    $this->get('/dokument/alla')->assertNotFound();
});

it('lists a payslip from a payroll run with its title and the archive link', function () {
    e20Seed($this->company, $this->owner);
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    $payslipId = (int) DB::table('payslips')->where('company_id', $this->company->id)->value('id');

    $rows = collect(DocumentOverview::page($payrollAdmin, $this->company, 'staff', '', 50)->items());
    $row = $rows->firstWhere('title', 'lonebesked-202601.pdf');

    expect($row)->not->toBeNull()
        ->and($row->url)->toBe(route('payroll.archive.payslip', ['payslip' => $payslipId]))
        ->and($row->subtitle)->toContain('1');
});

it('shows a quote request with invoices:view without crm:view, and an agreement request with crm:view without invoices:view', function () {
    e20Seed($this->company, $this->owner);
    $approver = Bookkeeping::member($this->company, 'approver');
    $sales = Bookkeeping::member($this->company, 'sales');

    $quoteOnly = collect(DocumentOverview::page($approver, $this->company, 'signing', '', 50)->items())->pluck('title')->all();
    $agreementOnly = collect(DocumentOverview::page($sales, $this->company, 'signing', '', 50)->items())->pluck('title')->all();

    expect($quoteOnly)->toContain('offertsignering-e20-1.pdf')->not->toContain('signering-e20-1.pdf')
        ->and($agreementOnly)->toContain('signering-e20-1.pdf')->not->toContain('offertsignering-e20-1.pdf');
});

it('names the quote or the agreement a signing file belongs to', function () {
    e20Seed($this->company, $this->owner);
    $rows = collect(DocumentOverview::page($this->owner, $this->company, 'signing', '', 50)->items());

    expect($rows->firstWhere('title', 'offertsignering-e20-1.pdf')->subtitle)->toContain('Offert 7')
        ->and($rows->firstWhere('title', 'signering-e20-1.pdf')->subtitle)->toContain('Avtal');
});

it('never lists a blocked inbox attachment', function () {
    e20Seed($this->company, $this->owner);

    expect(collect(DocumentOverview::page($this->owner, $this->company, 'inbox', '', 50)->items())->pluck('title')->all())
        ->toContain('inkorg-e20-1.pdf')->not->toContain('blockerad-e20.exe');
});

it('hides a personnel document without payroll:view-sensitive and shows its decrypted title to the owner while Lön is off', function () {
    $modules = app(ModuleManager::class);
    $modules->enable(PayrollModule::KEY, $this->company, $this->owner);
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    DocumentSupport::store($payrollAdmin, $this->company, ['title' => 'Anställningsavtal-e20', 'retention_class' => 'PERSONNEL', 'contains_personal_data' => '1']);
    $titles = fn (User $user): array => collect(DocumentOverview::page($user, $this->company, 'document', '', 50)->items())->pluck('title')->all();

    expect($titles($this->owner))->not->toContain('Anställningsavtal-e20')
        ->and($titles($payrollAdmin))->toContain('Anställningsavtal-e20');

    $modules->disable(PayrollModule::KEY, $this->company, $this->owner, true);

    expect($titles($this->owner->fresh()))->toContain('Anställningsavtal-e20');
});
