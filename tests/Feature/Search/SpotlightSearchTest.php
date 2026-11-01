<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\RecentItem;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Search\SearchResult;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;
use Tests\Support\Spotlight;
use Tests\Support\StaffLedger;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
});

it('finds a voucher by series and number, written together or apart', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, 875000);
    $reference = $voucher->reference();

    expect(Spotlight::titles($this->owner, $this->company, $reference, 'vouchers'))->toBe([$reference])
        ->and(Spotlight::titles($this->owner, $this->company, substr($reference, 0, 1).' '.substr($reference, 1), 'vouchers'))->toBe([$reference]);
});

it('finds a voucher by an amount on any row, in Swedish notation', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, 875000);
    Bookkeeping::post($this->owner, $this->company, 12300);

    foreach (['8750', '8 750', '8 750,00', '8750 kr'] as $typed) {
        expect(Spotlight::titles($this->owner, $this->company, $typed, 'vouchers'))->toContain($voucher->reference());
    }

    expect(Spotlight::titles($this->owner, $this->company, '> 5000 kr', 'vouchers'))->toBe([$voucher->reference()]);
});

it('finds vouchers by date and by description', function () {
    $october = Bookkeeping::post($this->owner, $this->company, 10000, date: '2026-10-14');
    Bookkeeping::post($this->owner, $this->company, 10000, date: '2026-03-01');

    expect(Spotlight::titles($this->owner, $this->company, '2026-10-14', 'vouchers'))->toBe([$october->reference()])
        ->and(Spotlight::titles($this->owner, $this->company, '14 okt', 'vouchers'))->toBe([$october->reference()])
        ->and(Spotlight::titles($this->owner, $this->company, 'oktober', 'vouchers'))->toBe([$october->reference()])
        ->and(Spotlight::titles($this->owner, $this->company, 'försälj', 'vouchers'))->toHaveCount(2);
});

it('ranks an exact number first, then a name that starts with the text, then one that contains it', function () {
    $exact = Spotlight::customer($this->company, 1029, 'Bageriet');
    Spotlight::customer($this->company, 2, 'Kund 1029 Hälsa AB');

    expect(Spotlight::titles($this->owner, $this->company, '1029', 'customers')[0])->toBe($exact->name);

    Spotlight::customer($this->company, 3, 'Stora Elverket AB');
    Spotlight::customer($this->company, 4, 'Elverket Syd AB');
    Spotlight::customer($this->company, 5, 'Elverket');

    expect(Spotlight::titles($this->owner, $this->company, 'elverket', 'customers'))->toBe(['Elverket', 'Elverket Syd AB', 'Stora Elverket AB']);
});

it('forgives a typo in a name', function () {
    Spotlight::supplier($this->company, 1, 'Elverket Malmö AB');

    expect(Spotlight::titles($this->owner, $this->company, 'elverkte', 'suppliers'))->toBe(['Elverket Malmö AB']);
});

it('finds customers by organisation number however it is typed and by email', function () {
    Spotlight::customer($this->company, 7, 'Fiskaren AB', ['org_number' => '556677-8899', 'email' => 'ekonomi@fiskaren.example']);

    expect(Spotlight::titles($this->owner, $this->company, '5566778899', 'customers'))->toBe(['Fiskaren AB'])
        ->and(Spotlight::titles($this->owner, $this->company, '556677-8899', 'customers'))->toBe(['Fiskaren AB'])
        ->and(Spotlight::titles($this->owner, $this->company, 'ekonomi@fiskaren', 'customers'))->toBe(['Fiskaren AB']);
});

it('understands "faktura 1029", OCR, the customer and "obetald"', function () {
    $customer = Spotlight::customer($this->company, 1, 'Fiskaren AB');
    Spotlight::invoice($this->company, $customer, 1029, 875000, ocr: '102937');
    Spotlight::invoice($this->company, $customer, 1030, 50000, status: 'PAID');

    $groups = Spotlight::search($this->owner, $this->company, 'faktura 1029');

    expect(array_keys($groups))->toBe(['invoices'])
        ->and($groups['invoices']->results[0]->title)->toBe('Faktura 1029')
        ->and(Spotlight::titles($this->owner, $this->company, '102937', 'invoices'))->toBe(['Faktura 1029'])
        ->and(Spotlight::titles($this->owner, $this->company, 'fiskaren', 'invoices'))->toHaveCount(2)
        ->and(Spotlight::titles($this->owner, $this->company, 'obetald', 'invoices'))->toBe(['Faktura 1029'])
        ->and(Spotlight::titles($this->owner, $this->company, 'fiskaren betalda', 'invoices'))->toBe(['Faktura 1030']);
});

it('understands "lev Elverket" and "att attestera" for supplier invoices', function () {
    $supplier = Spotlight::supplier($this->company, 1, 'Elverket AB');
    Spotlight::supplierInvoice($this->company, $supplier, 5, 125000);
    Spotlight::supplierInvoice($this->company, $supplier, 6, 99000, 'PAID');

    $groups = Spotlight::search($this->owner, $this->company, 'lev Elverket');

    expect(array_keys($groups))->toEqualCanonicalizing(['suppliers', 'supplier-invoices'])
        ->and($groups['supplier-invoices']->results)->toHaveCount(2)
        ->and(Spotlight::titles($this->owner, $this->company, 'attestera', 'supplier-invoices'))->toBe(['Leverantörsfaktura 5']);
});

it('finds accounts by number, by the start of the number and by name', function () {
    expect(Spotlight::titles($this->owner, $this->company, 'konto 1930', 'accounts')[0])->toStartWith('1930 ')
        ->and(Spotlight::titles($this->owner, $this->company, '19', 'accounts'))->each->toStartWith('19')
        ->and(Spotlight::titles($this->owner, $this->company, 'bank', 'accounts'))->not->toBeEmpty();
});

it('goes to pages and offers create actions, filtered by permission', function () {
    $groups = Spotlight::search($this->owner, $this->company, 'moms');

    expect(collect($groups['pages']->results)->pluck('title'))->toContain('Moms')
        ->and(collect(Spotlight::search($this->owner, $this->company, 'ny faktura')['actions']->results)->pluck('title')->first())->toBe('Ny faktura');

    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(Spotlight::search($auditor, $this->company, 'ny faktura'))->not->toHaveKey('actions')
        ->and(collect(Spotlight::search($auditor, $this->company, 'kontoplan')['pages']->results)->pluck('title'))->toContain('Kontoplan');
});

it('shows each role only the kinds of records it may see', function () {
    $customer = Spotlight::customer($this->company, 1, 'Synlig Kund AB');
    Spotlight::invoice($this->company, $customer, 77, 10000);
    $supplier = Spotlight::supplier($this->company, 2, 'Synlig Leverantör AB');
    Spotlight::supplierInvoice($this->company, $supplier, 77, 10000);
    Bookkeeping::post($this->owner, $this->company, 10000);

    // An employee in payroll's self-service is a member of the company but sees no bookkeeping.
    Payroll::setUp($this->company, $this->owner);
    $employee = Bookkeeping::member($this->company, 'employee');

    expect(Spotlight::search($employee, $this->company, 'synlig'))->toBe([])
        ->and(Spotlight::search($employee, $this->company, '77'))->not->toHaveKeys(['vouchers', 'invoices', 'supplier-invoices', 'customers']);

    // An approver sees supplier invoices to approve, and reads invoices and vouchers, but creates nothing.
    $approver = Bookkeeping::member($this->company, 'approver');

    expect(Spotlight::search($approver, $this->company, 'synlig'))->toHaveKeys(['suppliers', 'supplier-invoices', 'customers', 'invoices'])
        ->and(Spotlight::search($approver, $this->company, 'ny leverantör'))->not->toHaveKey('actions');

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(Spotlight::search($bookkeeper, $this->company, 'synlig'))->toHaveKeys(['customers', 'invoices', 'suppliers', 'supplier-invoices']);
});

it('finds nothing from a module that is off, and its records once it is on', function () {
    StaffLedger::enable($this->company, $this->owner);
    StaffLedger::person($this->owner, $this->company, overrides: ['first_name' => 'Sara', 'last_name' => 'Kock']);

    expect(Spotlight::titles($this->owner, $this->company, 'sara', 'staff'))->toBe(['Sara Kock']);

    app(ModuleManager::class)->disable(StaffLedgerModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(Spotlight::search($this->owner->fresh(), $this->company, 'sara'))->not->toHaveKey('staff');
});

it('finds inbox messages by sender and subject only while the inbox is on', function () {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    InboxMessage::create([
        'channel' => 'MAILGUN', 'recipient' => 'in@in.nonox.app', 'from_email' => 'faktura@elverket.example', 'from_name' => 'Elverket', 'subject' => 'Faktura oktober',
        'received_at' => '2026-10-31 07:00:00', 'eml_path' => 'x.eml', 'eml_sha256' => str_repeat('a', 64), 'eml_size' => 1, 'status' => 'NEEDS_REVIEW',
    ]);

    expect(Spotlight::titles($this->owner, $this->company, 'elverket', 'inbox'))->toBe(['Faktura oktober']);

    app(ModuleManager::class)->disable(InboxModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(Spotlight::search($this->owner->fresh(), $this->company, 'elverket'))->not->toHaveKey('inbox');
});

it('matches an employee on the last four digits of the personal number only with view-sensitive, and never shows it', function () {
    Payroll::setUp($this->company, $this->owner);
    $employee = Payroll::employee($this->owner, $this->company);
    $lastFour = substr((string) CurrentCompany::run($this->company, fn () => $employee->fresh()->personal_number), -4);

    // The owner has payroll:view but not payroll:view-sensitive (PAYROLL_MODULE.md B.2).
    expect(Spotlight::titles($this->owner, $this->company, $lastFour, 'employees'))->toBe([])
        ->and(Spotlight::titles($this->owner, $this->company, 'anna', 'employees'))->toBe(['Anna Kock']);

    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    $result = Spotlight::search($payrollAdmin, $this->company, $lastFour)['employees']->results[0];

    expect($result->title)->toBe('Anna Kock')
        ->and($result->subtitle)->toContain("slutar på {$lastFour}")
        ->and(json_encode($result))->not->toContain('900615');
});

it('offers the user\'s other companies and switches through the existing route', function () {
    [, $other] = Bookkeeping::company();
    $other->users()->attach($this->owner, ['role' => 'admin']);
    $other->forceFill(['name' => 'Systerbolaget Norr AB'])->save();

    $result = Spotlight::search($this->owner, $this->company, 'systerbolaget')['companies']->results[0];

    expect($result->action)->toBe(SearchResult::SWITCH_COMPANY)
        ->and($result->payload)->toBe(['team_id' => $other->id]);
});

it('shows recently opened records and things to do on an empty field', function () {
    $voucher = Bookkeeping::draft($this->owner, $this->company);

    $this->actingAs($this->owner)->post(route('search.recent'), ['type' => 'vouchers', 'id' => $voucher->id])->assertNoContent();

    $groups = Spotlight::search($this->owner, $this->company, '');

    expect($groups['recent']->results[0]->title)->toBe('Utkast')
        ->and(collect($groups['todo']->results)->pluck('id'))->toContain('voucher-drafts')
        ->and(collect($groups['shortcuts']->results)->pluck('title')->first())->toBe('Ny verifikation');
});

it('remembers a record when its page is opened', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company);

    $this->actingAs($this->owner)->get(route('accounting.vouchers.show', $voucher))->assertOk();
    app()->terminate();

    expect(RecentItem::query()->where('user_id', $this->owner->id)->where('record_id', $voucher->id)->exists())->toBeTrue();
});

it('streams the groups as JSON lines from a POST, never from the URL', function () {
    Spotlight::customer($this->company, 1, 'Strömmande AB');

    $response = $this->actingAs($this->owner)->post(route('search'), ['q' => 'kund strömmande']);
    $response->assertOk()->assertHeader('Content-Type', 'application/x-ndjson; charset=utf-8');

    $lines = array_map(fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", $response->streamedContent()))));

    expect($lines[0]['type'])->toBe('meta')
        ->and($lines[0]['chips'][0]['label'])->toBe('Kunder')
        ->and($lines[1]['key'])->toBe('customers')
        ->and($lines[1]['results'][0]['title'])->toBe('Strömmande AB')
        ->and(end($lines)['type'])->toBe('done');

    $this->actingAs($this->owner)->get('/sok?q=x')->assertStatus(405);
});

it('keeps the number of queries independent of the number of records', function () {
    $customer = Spotlight::customer($this->company, 1, 'Mängd AB');

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Spotlight::search($this->owner, $this->company, 'mängd');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 3) as $number) {
        Spotlight::invoice($this->company, $customer, $number, 1000);
        Bookkeeping::post($this->owner, $this->company, 1000);
    }

    $few = $count();

    foreach (range(4, 30) as $number) {
        Spotlight::invoice($this->company, $customer, $number, 1000);
    }

    expect($count())->toBe($few)->and($few)->toBeLessThan(40);
});

it('returns at most five per group, with the number of matches and a link to the list', function () {
    foreach (range(1, 8) as $number) {
        Spotlight::customer($this->company, $number, "Femma {$number} AB");
    }

    $group = Spotlight::search($this->owner, $this->company, 'femma')['customers'];

    expect($group->results)->toHaveCount(5)
        ->and($group->total)->toBe(8)
        ->and($group->jsonSerialize()['more'])->toContain('/kunder?sok=femma');
});

it('finds every account in a large chart without loading it', function () {
    expect(Account::query()->count())->toBeGreaterThan(20)
        ->and(Spotlight::titles($this->owner, $this->company, '1930', 'accounts'))->toHaveCount(1);
});
