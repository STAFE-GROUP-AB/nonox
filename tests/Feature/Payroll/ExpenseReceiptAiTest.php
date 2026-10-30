<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Voucher;
use App\Modules\Ai\Agents\ExpenseReceiptAgent;
use App\Modules\Ai\AiModule;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Livewire\AbsenceRequestList;
use App\Modules\Payroll\Livewire\MyExpenses;
use App\Modules\Payroll\Models\Expense;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->worker = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = Payroll::employee($this->owner, $this->company, ['user_id' => $this->worker->id]);
    app(ModuleManager::class)->enable(AiModule::KEY, $this->company, $this->owner);
});

function receiptAiKey(): void
{
    app(ModuleManager::class)->updateSettings(AiModule::KEY, test()->company, test()->owner, ['provider' => 'OPENAI', 'api_keys' => ['OPENAI' => Crypt::encryptString('sk-test-abcdefghijklmnopqrstuvwxyz0123456789ABCD')]]);
}

/** @return array<string, mixed> what the agent reads from the lemon receipt */
function lemonReading(array $overrides = []): array
{
    return ['is_receipt' => true, 'date' => '2026-04-08', 'description' => 'Citroner från ICA till baren', 'total' => 250.0, 'vat_total' => 26.79, 'account' => 4010, 'notes' => '', ...$overrides];
}

function sendReceipt(): Expense
{
    test()->actingAs(test()->worker);

    Livewire::test(MyExpenses::class)
        ->call('start')
        ->assertDontSee('Vad köpte du?')
        ->assertSee('NONOX AI läser datum')
        ->set('receipt', UploadedFile::fake()->image('kvitto.jpg', 400, 600))
        ->call('send')
        ->assertDispatched('toast');

    return Expense::query()->latest('id')->firstOrFail();
}

it('lets an employee send only the receipt and has NONOX AI read the rest for approval', function () {
    receiptAiKey();
    ExpenseReceiptAgent::fake([lemonReading()]);

    $expense = sendReceipt();

    expect($expense->ai_status)->toBe('DONE')
        ->and($expense->status)->toBe('SUBMITTED')
        ->and($expense->date->toDateString())->toBe('2026-04-08')
        ->and($expense->description)->toBe('Citroner från ICA till baren')
        ->and($expense->amount_ore->ore)->toBe(25_000)
        ->and($expense->vat_ore->ore)->toBe(2_679)
        ->and($expense->ai_account_number)->toBe(4010)
        ->and($expense->voucher_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'ai.document_analyzed')->where('payload->kind', 'expense_receipt')->exists())->toBeTrue();

    $this->actingAs($this->owner);
    Livewire::test(AbsenceRequestList::class)
        ->assertSee('Läst av NONOX AI')
        ->assertSet("expenseAccount.{$expense->id}", '4010')
        ->assertSet("expenseDescription.{$expense->id}", 'Citroner från ICA till baren')
        ->call('approveExpense', $expense->id)
        ->assertDispatched('toast');

    $expense->refresh();
    $rows = Voucher::query()->findOrFail($expense->voucher_id)->load('rows.account')->rows;
    $by = fn (int $account): int => $rows->filter(fn ($row) => $row->account->number === $account)->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore);

    expect($expense->status)->toBe('APPROVED')
        ->and($by(4010))->toBe(22_321)
        ->and($by(2641))->toBe(2_679)
        ->and($by(2890))->toBe(-25_000);
});

it('leaves an unreadable receipt for the approver to fill in, and never approves it without an amount', function () {
    $expense = sendReceipt();

    expect($expense->ai_status)->toBe('FAILED')
        ->and($expense->ai_notes)->toContain('API-nyckel')
        ->and($expense->amount_ore->ore)->toBe(0);

    $this->actingAs($this->owner);
    $page = Livewire::test(AbsenceRequestList::class)
        ->assertSee('NONOX AI kunde inte läsa allt')
        ->set("expenseAccount.{$expense->id}", '4010')
        ->call('approveExpense', $expense->id);

    expect($expense->refresh()->status)->toBe('SUBMITTED');

    $page->set("expenseAmount.{$expense->id}", '120')
        ->set("expenseVat.{$expense->id}", '24')
        ->set("expenseDescription.{$expense->id}", 'Diskmedel')
        ->set("expenseDate.{$expense->id}", '2026-04-07')
        ->call('approveExpense', $expense->id);

    $expense->refresh();
    expect($expense->status)->toBe('APPROVED')
        ->and($expense->amount_ore->ore)->toBe(12_000)
        ->and($expense->vat_ore->ore)->toBe(2_400)
        ->and($expense->description)->toBe('Diskmedel')
        ->and($expense->date->toDateString())->toBe('2026-04-07')
        ->and(AuditLog::query()->where('action', 'payroll.expense_approved')->sole()->payload['corrected'])->toBe(['date', 'description', 'amount']);
});

it('reads a receipt again on request and drops what cannot be trusted', function () {
    receiptAiKey();
    ExpenseReceiptAgent::fake([
        lemonReading(['is_receipt' => false, 'notes' => 'Bilden är suddig.']),
        lemonReading(['account' => 9999, 'date' => '2027-01-01', 'vat_total' => 300.0]),
    ]);

    $expense = sendReceipt();
    expect($expense->ai_status)->toBe('FAILED')
        ->and($expense->ai_notes)->toBe('Filen ser inte ut som ett kvitto. Bilden är suddig.');

    $this->actingAs($this->owner);
    Livewire::test(AbsenceRequestList::class)
        ->call('readExpenseAgain', $expense->id)
        ->assertDispatched('toast')
        ->assertSet("expenseAccount.{$expense->id}", '');

    $expense->refresh();
    expect($expense->ai_status)->toBe('DONE')
        ->and($expense->ai_account_number)->toBeNull()
        ->and($expense->date->toDateString())->toBe('2026-04-10')
        ->and($expense->vat_ore->ore)->toBe(0)
        ->and($expense->ai_notes)->toContain('Datumet gick inte att läsa')
        ->and($expense->ai_notes)->toContain('Momsen gick inte att läsa');
});

it('asks for the details as before when NONOX AI is off', function () {
    app(ModuleManager::class)->disable(AiModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(fn () => app(ExpenseClaims::class)->submit($this->worker, $this->company, [], UploadedFile::fake()->image('kvitto.jpg')))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->worker);
    Livewire::test(MyExpenses::class)->call('start')->assertSee('Vad köpte du?');
    expect(Expense::query()->count())->toBe(0);
});
