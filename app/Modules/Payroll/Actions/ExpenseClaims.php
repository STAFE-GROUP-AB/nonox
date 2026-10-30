<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Outlays (utlägg) an employee claims on Min sida with a receipt. Payroll
 * staff approve them with a cost account: the approval books the cost and the
 * input VAT against 2890 with the receipt attached, and the next payroll run
 * repays the employee through the same account (PAYROLL_MODULE.md B.11).
 */
class ExpenseClaims
{
    /** What the company owes the employee until the outlay is repaid with the pay. */
    public const int LIABILITY = 2890;

    public const int INPUT_VAT = 2641;

    private const array RECEIPT_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];

    public function __construct(private readonly ModuleManager $modules, private readonly BookVoucher $book, private readonly AttachToVoucher $attach) {}

    /**
     * @param  array<string, mixed>  $input  date, description, amount, vat
     */
    public function submit(User $user, Company $company, array $input, UploadedFile $receipt): Expense
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'self:expenses')) {
            throw new AuthorizationException;
        }

        $validated = Validator::make([...$input, 'receipt' => $receipt], [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string'],
            'vat' => ['nullable', 'string'],
            'receipt' => ['required', 'file', 'max:10240', 'mimes:'.implode(',', self::RECEIPT_TYPES)],
        ], [
            'receipt.required' => __('Lägg till en bild eller PDF av kvittot.'),
            'receipt.mimes' => __('Kvittot ska vara en bild eller en PDF.'),
            'date.before_or_equal' => __('Datumet kan inte vara i framtiden.'),
        ], ['date' => __('datum'), 'description' => __('vad utlägget gäller'), 'amount' => __('belopp')])->validate();

        try {
            $amount = Money::parse((string) $validated['amount']);
            $vat = blank($validated['vat'] ?? null) ? Money::zero() : Money::parse((string) $validated['vat']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Beloppet går inte att läsa. Skriv till exempel 249 eller 249,50.')]);
        }

        if ($amount->ore <= 0 || $vat->ore < 0 || $vat->ore >= $amount->ore) {
            throw ValidationException::withMessages(['amount' => __('Beloppet ska vara större än noll och momsen mindre än beloppet.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $validated, $amount, $vat, $receipt): Expense {
            $employee = Employee::query()->where('user_id', $user->id)->first()
                ?? throw ValidationException::withMessages(['amount' => __('Ditt konto är inte kopplat till någon anställd. Be din arbetsgivare koppla det.')]);

            $extension = strtolower($receipt->getClientOriginalExtension());
            $extension = in_array($extension, self::RECEIPT_TYPES, true) ? $extension : 'jpg';
            $path = (string) $receipt->storeAs("expense-receipts/{$company->id}", basename('kvitto-'.time().'-'.uniqid().'.'.$extension), AttachToVoucher::DISK);

            $expense = Expense::create([
                'employee_id' => $employee->id,
                'type' => 'OUTLAY',
                'date' => $validated['date'],
                'description' => $validated['description'],
                'amount_ore' => $amount->ore,
                'vat_ore' => $vat->ore,
                'receipt_path' => $path,
                'receipt_name' => mb_substr($receipt->getClientOriginalName(), 0, 255),
                'receipt_mime' => (string) $receipt->getMimeType(),
                'status' => 'SUBMITTED',
                'requested_by' => $user->id,
            ]);
            Audit::record('payroll.expense_submitted', $expense, ['employee_id' => $employee->id, 'amount_ore' => $amount->ore], $company, $user);

            return $expense;
        });
    }

    /**
     * Approves the outlay and books it: the cost and input VAT against what
     * the company now owes the employee, with the receipt as underlag.
     */
    public function approve(User $user, Expense $expense, int $accountNumber, ?string $vat = null): Expense
    {
        $company = $this->staff($user, $expense);

        return CurrentCompany::run($company, fn (): Expense => DB::transaction(function () use ($user, $company, $expense, $accountNumber, $vat): Expense {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['expense' => __('Utlägget är redan besvarat.')]);
            }

            if ($expense->requested_by === $user->id && ($this->modules->settings(PayrollModule::KEY, $company)['segregation_of_duties'] ?? true)) {
                throw ValidationException::withMessages(['expense' => __('Du kan inte godkänna ditt eget utlägg. Någon annan med ansvar för lönerna behöver göra det.')]);
            }

            $account = Account::query()->where('number', $accountNumber)->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['account' => __('Konto :number finns inte i kontoplanen.', ['number' => $accountNumber])]);
            $vatOre = blank($vat) ? $expense->vat_ore->ore : Money::parse((string) $vat)->ore;

            if ($vatOre < 0 || $vatOre >= $expense->amount_ore->ore) {
                throw ValidationException::withMessages(['vat' => __('Momsen ska vara mindre än beloppet.')]);
            }

            $employee = Employee::query()->findOrFail($expense->employee_id);
            $rows = array_values(array_filter([
                ['account' => $account->number, 'amount' => $expense->amount_ore->ore - $vatOre, 'description' => $expense->description],
                $vatOre > 0 ? ['account' => self::INPUT_VAT, 'amount' => $vatOre, 'description' => null] : null,
                ['account' => self::LIABILITY, 'amount' => -$expense->amount_ore->ore, 'description' => __('Utlägg att betala till :name', ['name' => "{$employee->first_name} {$employee->last_name}"])],
            ]));
            $voucher = $this->book->rows($user, $company, $expense->date, __('Utlägg :name: :what', ['name' => $employee->first_name, 'what' => (string) $expense->description]), 'L', $rows, VoucherSource::Payroll, $expense);

            if ($expense->receipt_path !== null && Storage::disk(AttachToVoucher::DISK)->exists($expense->receipt_path)) {
                $contents = (string) Storage::disk(AttachToVoucher::DISK)->get($expense->receipt_path);
                $this->attach->linkStoredFile($user, $voucher, $expense->receipt_path, (string) $expense->receipt_name, (string) $expense->receipt_mime, strlen($contents), hash('sha256', $contents));
            }

            $expense->forceFill(['status' => 'APPROVED', 'account_id' => $account->id, 'vat_ore' => $vatOre, 'decided_by' => $user->id, 'decided_at' => now(), 'voucher_id' => $voucher->id])->save();
            Audit::record('payroll.expense_approved', $expense, ['voucher' => $voucher->reference()], $company, $user);

            return $expense;
        }));
    }

    public function reject(User $user, Expense $expense, string $reason): Expense
    {
        $company = $this->staff($user, $expense);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Skriv varför, så att den anställde förstår beslutet.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $expense, $reason): Expense {
            if ($expense->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['expense' => __('Utlägget är redan besvarat.')]);
            }

            $expense->forceFill(['status' => 'REJECTED', 'rejection_reason' => trim($reason), 'decided_by' => $user->id, 'decided_at' => now()])->save();
            Audit::record('payroll.expense_rejected', $expense, [], $company, $user);

            return $expense;
        });
    }

    private function staff(User $user, Expense $expense): Company
    {
        $company = Company::query()->findOrFail($expense->company_id);
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:run')) {
            throw new AuthorizationException;
        }

        return $company;
    }

    /** The date an outlay was paid out, for the payslip text. */
    public static function label(Expense $expense): string
    {
        return __('Utlägg :date: :what', ['date' => $expense->date->toDateString(), 'what' => (string) $expense->description]);
    }
}
