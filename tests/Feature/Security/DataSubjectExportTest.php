<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Privacy\EraseRegisterEntry;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LoginCode;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

it('gives a user everything about their own account as JSON', function () {
    $json = $this->get(route('privacy.export.me'))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="mina-uppgifter.json"')
        ->json();

    expect($json['konto']['e_post'])->toBe($this->owner->email)
        ->and($json['foretag'][0]['namn'])->toBe($this->company->name)
        ->and(AuditLog::query()->where('action', 'privacy.exported')->exists())->toBeTrue();
});

it('exports a customer with the personal number to those who manage customers', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Privat Person', 'personal_number' => '19900615123'.Luhn::checkDigit('900615123'), 'payment_terms_days' => 30]);

    $json = $this->get(route('privacy.export.customer', $customer))->assertOk()->json();

    expect($json['kund']['name'])->toBe('Privat Person')
        ->and($json['kund']['personal_number'])->not->toBeNull();

    $this->actingAs(Bookkeeping::member($this->company, 'auditor'))->get(route('privacy.export.customer', $customer))->assertForbidden();
});

it('exports an employee only to those who may see sensitive payroll data', function () {
    Payroll::setUp($this->company, $this->owner);
    $admin = Bookkeeping::member($this->company, 'payroll_admin');
    $employee = Payroll::employee($admin, $this->company);

    $this->get(route('privacy.export.employee', $employee))->assertForbidden();

    $json = $this->actingAs($admin)->get(route('privacy.export.employee', $employee))->assertOk()->json();

    expect($json['anstalld']['personnummer'])->toBe($employee->personal_number)
        ->and($json['anstallningar'])->toHaveCount(1);
});

it('deletes a customer that never reached the books and anonymises one that did', function () {
    $unused = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Aldrig Fakturerad', 'payment_terms_days' => 30]);

    expect(app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $unused))->toBe('deleted')
        ->and(Customer::query()->find($unused->id))->toBeNull();

    $used = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Fakturerad Person', 'email' => 'p@example.test', 'payment_terms_days' => 30]);
    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $used->id, 'invoice_date' => now()->toDateString(), 'rows' => [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
    $invoice = app(IssueInvoice::class)($this->owner, $draft);

    expect(fn () => app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $used))->toThrow(ValidationException::class);

    $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();

    expect(app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $used->fresh()))->toBe('anonymised')
        ->and($used->fresh()->email)->toBeNull()
        ->and($used->fresh()->name)->toStartWith('Anonymiserad kund')
        ->and($invoice->fresh()->customer_snapshot['name'])->toBe('Fakturerad Person');
});

it('prunes personal data that has no purpose left', function () {
    LoginCode::query()->create(['email' => 'gammal@example.test', 'code_hash' => str_repeat('a', 64), 'expires_at' => now()->subDays(3), 'attempts' => 0])
        ->forceFill(['created_at' => now()->subDays(3)])->save();

    $this->artisan('privacy:prune')->assertSuccessful();

    expect(LoginCode::query()->where('email', 'gammal@example.test')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'privacy.pruned')->exists())->toBeTrue();
});
