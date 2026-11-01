<?php

declare(strict_types=1);

use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\AbsenceRequestList;
use App\Modules\Payroll\Livewire\MyExpenses;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Files\InlineFile;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    $this->worker = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = Payroll::employee($this->owner, $this->company, ['user_id' => $this->worker->id]);
    $this->claims = app(ExpenseClaims::class);
});

function claimLemons(): Expense
{
    return test()->claims->submit(test()->worker, test()->company, ['date' => '2026-04-08', 'description' => 'Citroner till baren', 'amount' => '250', 'vat' => '26,79'], UploadedFile::fake()->image('kvitto.jpg', 400, 600));
}

it('lets an employee claim an outlay with a photo of the receipt on Min sida', function () {
    $this->actingAs($this->worker);

    Livewire::test(MyExpenses::class)
        ->call('start')
        ->set('expense.date', '2026-04-08')
        ->set('expense.description', 'Citroner till baren')
        ->set('expense.amount', '250')
        ->set('expense.vat', '26,79')
        ->call('send')
        ->assertHasErrors('receipt')
        ->set('receipt', UploadedFile::fake()->image('kvitto.jpg', 400, 600))
        ->call('send')
        ->assertDispatched('toast')
        ->assertSee('Väntar på svar');

    $expense = Expense::query()->sole();
    expect($expense->amount_ore->ore)->toBe(25_000)
        ->and($expense->vat_ore->ore)->toBe(2_679)
        ->and(Storage::disk('local')->exists($expense->receipt_path))->toBeTrue();
});

it('books an approved outlay with the receipt and repays it once with the pay', function () {
    $expense = claimLemons();
    $this->actingAs($this->owner);

    Livewire::test(AbsenceRequestList::class)
        ->assertSee('Citroner till baren')
        ->set("expenseAccount.{$expense->id}", '4010')
        ->call('approveExpense', $expense->id)
        ->assertDispatched('toast');

    $expense->refresh();
    $rows = Voucher::query()->findOrFail($expense->voucher_id)->load('rows.account')->rows;
    $by = fn (int $account): int => $rows->filter(fn ($row) => $row->account->number === $account)->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore);

    expect($expense->status)->toBe('APPROVED')
        ->and($by(4010))->toBe(22_321)
        ->and($by(2641))->toBe(2_679)
        ->and($by(2890))->toBe(-25_000)
        ->and(VoucherAttachment::query()->where('voucher_id', $expense->voucher_id)->count())->toBe(1);

    $runs = app(PayrollRuns::class);
    $april = $runs->create($this->owner, $this->company, '2026-04');
    $runs->calculate($this->owner, $april);
    $line = Payslip::query()->with('lines')->sole()->lines->first(fn ($line) => PayCode::query()->find($line->pay_code_id)?->code === 'UTLAGG');

    expect($line->amount_ore->ore)->toBe(25_000)
        ->and($line->tax_effect)->toBe('NET');

    $runs->approve($this->admin, $april->fresh());
    $runs->markPaid($this->owner, $april->fresh());
    expect($expense->fresh()->status)->toBe('PAID');

    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    $may = $runs->create($this->owner, $this->company, '2026-05');
    $runs->calculate($this->owner, $may);
    expect(Payslip::query()->with('lines')->where('payroll_run_id', $may->id)->sole()->lines->filter(fn ($line) => PayCode::query()->find($line->pay_code_id)?->code === 'UTLAGG')->count())->toBe(0);
});

it('keeps outlays and receipts to payroll staff, and nobody approves their own', function () {
    $expense = claimLemons();
    [$stranger] = Bookkeeping::company();

    expect(fn () => $this->claims->approve($this->worker, $expense, 4010))->toThrow(AuthorizationException::class);

    $this->actingAs($this->owner)->get(route('payroll.expenses.receipt', $expense))->assertOk()
        ->assertHeader('Content-Security-Policy', InlineFile::CONTENT_SECURITY_POLICY)->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($stranger)->get(route('payroll.expenses.receipt', $expense))->assertNotFound();

    Payroll::employee($this->owner, $this->company, ['first_name' => 'Ägaren', 'personal_number' => '199001011239', 'user_id' => $this->owner->id]);
    $own = $this->claims->submit($this->owner, $this->company, ['date' => '2026-04-08', 'description' => 'Eget', 'amount' => '100'], UploadedFile::fake()->image('k.jpg', 200, 200));

    expect(fn () => $this->claims->approve($this->owner, $own, 4010))->toThrow(ValidationException::class, 'ditt eget utlägg');
});
