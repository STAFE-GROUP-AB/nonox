<?php

declare(strict_types=1);

use App\Actions\Payables\SavePaymentBankSettings;
use App\Models\AuditLog;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Actions\SalaryPaymentFile;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Payroll::employee($this->owner, $this->company, ['bank_clearing' => '8327', 'bank_account' => '9934567890']);
    $this->runs = app(PayrollRuns::class);
    $this->run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $this->run);
    $this->runs->approve($this->admin, $this->run->fresh());
});

it('writes a pain.001 salary file with category SALA to each employee\'s bank account', function () {
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);

    $file = app(SalaryPaymentFile::class)($this->owner, $this->run->fresh(), true);
    $xml = simplexml_load_string($file['xml']);
    $xml->registerXPathNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03');
    $net = Payslip::query()->sole()->net_ore->ore;

    expect($file['filename'])->toBe('Lonefil-2026-04.xml')
        ->and((string) $xml->xpath('//p:PmtTpInf/p:CtgyPurp/p:Cd')[0])->toBe('SALA')
        ->and((string) $xml->xpath('//p:CdtTrfTxInf/p:CdtrAcct/p:Id/p:Othr/p:Id')[0])->toBe('83279934567890')
        ->and((string) $xml->xpath('//p:CdtTrfTxInf/p:CdtrAcct/p:Id/p:Othr/p:SchmeNm/p:Cd')[0])->toBe('BBAN')
        ->and((string) $xml->xpath('//p:CdtTrfTxInf/p:CdtrAgt/p:FinInstnId/p:ClrSysMmbId/p:MmbId')[0])->toBe('8327')
        ->and((string) $xml->xpath('//p:CdtTrfTxInf/p:Amt/p:InstdAmt')[0])->toBe(number_format($net / 100, 2, '.', ''))
        ->and((string) $xml->xpath('//p:ReqdExctnDt')[0])->toBe('2026-04-24');
});

it('refuses a salary file without the company\'s bank or an employee\'s account', function () {
    expect(fn () => app(SalaryPaymentFile::class)($this->owner, $this->run->fresh(), true))->toThrow(ValidationException::class, 'Välj bank');

    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);
    Employee::query()->update(['bank_account' => null]);

    expect(fn () => app(SalaryPaymentFile::class)($this->owner, $this->run->fresh(), true))->toThrow(ValidationException::class, 'Bankkonto saknas för Anna Kock');
});

it('books tax and contributions against the tax account once, after the salary is posted', function () {
    app(AddPayrollAccounts::class)($this->owner, $this->company);
    $this->runs->markPaid($this->owner, $this->run->fresh());

    expect(fn () => $this->runs->postTax($this->owner, $this->run->fresh(), CarbonImmutable::parse('2026-05-12')))->toThrow(ValidationException::class);

    $this->runs->post($this->owner, $this->run->fresh());
    $run = $this->runs->postTax($this->owner, $this->run->fresh(), CarbonImmutable::parse('2026-05-12'));
    $payslip = Payslip::query()->sole();
    $rows = $run->taxVoucher->load('rows.account')->rows;
    $by = fn (int $account): int => $rows->filter(fn ($row) => $row->account->number === $account)->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore);

    expect($by(2710))->toBe($payslip->tax_ore->ore)
        ->and($by(2730))->toBe($payslip->employer_contribution_ore->ore)
        ->and($by(1630))->toBe(-($payslip->tax_ore->ore + $payslip->employer_contribution_ore->ore))
        ->and($run->taxVoucher->voucher_date->toDateString())->toBe('2026-05-12')
        ->and(fn () => $this->runs->postTax($this->owner, $run->fresh(), CarbonImmutable::parse('2026-05-12')))->toThrow(ValidationException::class, 'bara en gång');
});

it('refuses the salary file without "Jag har granskat siffrorna" and records the confirmation when given', function () {
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);

    expect(fn () => app(SalaryPaymentFile::class)($this->owner, $this->run->fresh()))->toThrow(ValidationException::class, 'granskat siffrorna');
    expect(AuditLog::query()->where('action', 'figures.reviewed')->count())->toBe(0);

    app(SalaryPaymentFile::class)($this->owner, $this->run->fresh(), true);

    $row = AuditLog::query()->where('action', 'figures.reviewed')->sole();
    expect($row->user_id)->toBe($this->owner->id)->and($row->payload)->toBe(['filing' => 'salary_payment_file']);
});
