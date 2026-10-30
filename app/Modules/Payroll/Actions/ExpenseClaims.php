<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ai\Actions\AnalyzeDocument;
use App\Modules\Ai\Support\AiAnalysisException;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Jobs\AnalyzeExpenseReceipt;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Outlays (utlägg) an employee claims on Min sida with a receipt. Payroll
 * staff approve them with a cost account: the approval books the cost and the
 * input VAT against 2890 with the receipt attached, and the next payroll run
 * repays the employee through the same account (PAYROLL_MODULE.md B.11).
 *
 * With NONOX AI turned on the employee sends only the receipt: the AI reads
 * date, what was bought, amount and VAT and proposes a cost account, and the
 * person who approves checks and corrects it. Nothing is booked before that.
 */
class ExpenseClaims
{
    /** What the company owes the employee until the outlay is repaid with the pay. */
    public const int LIABILITY = 2890;

    public const int INPUT_VAT = 2641;

    private const array RECEIPT_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];

    public const string AI_PENDING = 'PENDING';

    public const string AI_DONE = 'DONE';

    public const string AI_FAILED = 'FAILED';

    public function __construct(private readonly ModuleManager $modules, private readonly BookVoucher $book, private readonly AttachToVoucher $attach, private readonly AnalyzeDocument $analyze) {}

    /**
     * @param  array<string, mixed>  $input  date, description, amount, vat; not used when NONOX AI reads the receipt
     */
    public function submit(User $user, Company $company, array $input, UploadedFile $receipt): Expense
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'self:expenses')) {
            throw new AuthorizationException;
        }

        if (AnalyzeDocument::readsReceiptsFor($company)) {
            return $this->submitReceipt($user, $company, $receipt);
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

        return $this->store($user, $company, $receipt, [
            'date' => $validated['date'],
            'description' => $validated['description'],
            'amount_ore' => $amount->ore,
            'vat_ore' => $vat->ore,
        ]);
    }

    /**
     * An outlay sent with only the receipt. NONOX AI reads the file while the
     * employee waits, and a document that is no outlay (an invoice still to
     * be paid, something that is no purchase, a photo that cannot be read)
     * is refused at once with the reason, so nothing reaches the approver.
     * When the AI itself cannot be reached the outlay is stored anyway, and
     * the person who approves fills in the details.
     */
    private function submitReceipt(User $user, Company $company, UploadedFile $receipt): Expense
    {
        Validator::make(['receipt' => $receipt], [
            'receipt' => ['required', 'file', 'max:10240', 'mimes:'.implode(',', self::RECEIPT_TYPES)],
        ], [
            'receipt.required' => __('Lägg till en bild eller PDF av kvittot.'),
            'receipt.mimes' => __('Kvittot ska vara en bild eller en PDF.'),
        ])->validate();

        $reading = $this->read($user, $company, (string) $receipt->getRealPath(), (string) $receipt->getMimeType());

        if (is_array($reading) && ($refusal = self::refusal($reading)) !== null) {
            Audit::record('payroll.expense_refused_by_ai', null, ['kind' => $reading['document_kind'] ?? null], $company, $user);

            throw ValidationException::withMessages(['receipt' => $refusal]);
        }

        $expense = $this->store($user, $company, $receipt, [
            'date' => CarbonImmutable::today()->toDateString(),
            'description' => null,
            'amount_ore' => 0,
            'vat_ore' => 0,
            'ai_status' => self::AI_PENDING,
        ]);

        return CurrentCompany::run($company, fn (): Expense => is_array($reading)
            ? $this->applyReading($company, $expense, $reading)
            : $this->readingFailed($expense, $reading));
    }

    /**
     * What NONOX AI read from the file, or why it could not read it.
     *
     * @return array<string, mixed>|string
     */
    private function read(User $user, Company $company, string $path, string $mime): array|string
    {
        try {
            return $this->analyze->expenseReceipt($user, $company, $path, $mime);
        } catch (AiAnalysisException $exception) {
            return $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            return __('NONOX AI kunde inte läsa kvittot. Fyll i uppgifterna själv eller låt NONOX AI läsa det igen.');
        }
    }

    /**
     * Why the document is no outlay, said to the employee; null when it is.
     *
     * @param  array<string, mixed>  $reading
     */
    private static function refusal(array $reading): ?string
    {
        $notes = trim((string) ($reading['notes'] ?? ''));
        $reason = match ($reading['document_kind'] ?? null) {
            'UNPAID_INVOICE' => __('Det här är en faktura som inte är betald, inte ett utlägg. Lämna den till den som sköter företagets fakturor. Har du betalat den själv, skicka kvittot eller betalningsbekräftelsen i stället.'),
            'NOT_A_PURCHASE' => __('Filen visar inget köp. Skicka kvittot för det du har betalat.'),
            'UNREADABLE' => __('Kvittot gick inte att läsa. Ta en ny bild där hela kvittot syns skarpt.'),
            default => null,
        };

        return $reason === null ? null : trim("{$reason} {$notes}");
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function store(User $user, Company $company, UploadedFile $receipt, array $details): Expense
    {
        return CurrentCompany::run($company, function () use ($user, $company, $receipt, $details): Expense {
            $employee = Employee::query()->where('user_id', $user->id)->first()
                ?? throw ValidationException::withMessages(['amount' => __('Ditt konto är inte kopplat till någon anställd. Be din arbetsgivare koppla det.')]);

            $extension = strtolower($receipt->getClientOriginalExtension());
            $extension = in_array($extension, self::RECEIPT_TYPES, true) ? $extension : 'jpg';
            $path = (string) $receipt->storeAs("expense-receipts/{$company->id}", basename('kvitto-'.time().'-'.uniqid().'.'.$extension), AttachToVoucher::DISK);

            $expense = Expense::create([
                ...$details,
                'employee_id' => $employee->id,
                'type' => 'OUTLAY',
                'receipt_path' => $path,
                'receipt_name' => mb_substr($receipt->getClientOriginalName(), 0, 255),
                'receipt_mime' => (string) $receipt->getMimeType(),
                'status' => 'SUBMITTED',
                'requested_by' => $user->id,
            ]);
            Audit::record('payroll.expense_submitted', $expense, ['employee_id' => $employee->id, 'amount_ore' => $details['amount_ore']], $company, $user);

            return $expense;
        });
    }

    /**
     * Lets NONOX AI read the receipt of an outlay still waiting for a
     * decision, and stores what it read as a proposal. A reading that fails
     * says why, so the person who approves fills in the details instead.
     */
    public function readReceipt(Expense $expense): Expense
    {
        $company = Company::query()->findOrFail($expense->company_id);

        return CurrentCompany::run($company, function () use ($company, $expense): Expense {
            if ($expense->status !== 'SUBMITTED' || $expense->ai_status !== self::AI_PENDING) {
                return $expense;
            }

            $user = User::query()->find($expense->requested_by);
            $disk = Storage::disk(AttachToVoucher::DISK);

            if ($user === null || $expense->receipt_path === null || ! $disk->exists($expense->receipt_path)) {
                return $this->readingFailed($expense, __('Kvittot finns inte kvar att läsa. Fyll i uppgifterna själv.'));
            }

            $reading = $this->read($user, $company, $disk->path($expense->receipt_path), (string) $expense->receipt_mime);

            if (is_string($reading)) {
                return $this->readingFailed($expense, $reading);
            }

            $refusal = self::refusal($reading);

            return $refusal === null
                ? $this->applyReading($company, $expense, $reading)
                : $this->readingFailed($expense, $refusal);
        });
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function applyReading(Company $company, Expense $expense, array $answer): Expense
    {
        $notes = array_filter([trim((string) ($answer['notes'] ?? ''))]);

        $total = (int) round(((float) ($answer['total'] ?? 0)) * 100);
        $vat = (int) round(((float) ($answer['vat_total'] ?? 0)) * 100);

        $date = (string) ($answer['date'] ?? '');
        $readDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && CarbonImmutable::parse($date)->lessThanOrEqualTo(CarbonImmutable::today()) ? $date : null;

        if ($readDate === null) {
            $notes[] = __('Datumet gick inte att läsa. Kontrollera det.');
        }

        if ($vat < 0 || ($total > 0 && $vat >= $total)) {
            $vat = 0;
            $notes[] = __('Momsen gick inte att läsa. Kontrollera den.');
        }

        $expense->forceFill([
            'date' => $readDate ?? $expense->date->toDateString(),
            'description' => mb_substr(trim((string) ($answer['description'] ?? '')), 0, 255) ?: null,
            'amount_ore' => max(0, $total),
            'vat_ore' => $total > 0 ? $vat : 0,
            'ai_account_number' => is_int($answer['account'] ?? null) ? $answer['account'] : null,
            'ai_status' => $total > 0 ? self::AI_DONE : self::AI_FAILED,
            'ai_notes' => $total > 0 ? (implode(' ', $notes) ?: null) : implode(' ', [__('Beloppet gick inte att läsa. Fyll i det själv.'), ...$notes]),
        ])->save();
        Audit::record('payroll.expense_read_by_ai', $expense, ['amount_ore' => $expense->amount_ore->ore, 'status' => $expense->ai_status], $company);

        return $expense;
    }

    private function readingFailed(Expense $expense, string $reason): Expense
    {
        Log::warning('NONOX AI could not read an outlay receipt.', ['company_id' => $expense->company_id, 'expense_id' => $expense->id, 'mime' => $expense->receipt_mime, 'reason' => $reason]);
        $expense->forceFill(['ai_status' => self::AI_FAILED, 'ai_notes' => $reason])->save();

        return $expense;
    }

    /** Has NONOX AI read the receipt again, for an outlay still waiting for a decision. */
    public function readAgain(User $user, Expense $expense): Expense
    {
        $company = $this->staff($user, $expense);

        if (! AnalyzeDocument::readsReceiptsFor($company)) {
            throw ValidationException::withMessages(['expense' => __('NONOX AI är inte påslaget för företaget.')]);
        }

        if ($expense->status !== 'SUBMITTED') {
            throw ValidationException::withMessages(['expense' => __('Utlägget är redan besvarat.')]);
        }

        CurrentCompany::run($company, fn () => $expense->forceFill(['ai_status' => self::AI_PENDING, 'ai_notes' => null])->save());
        AnalyzeExpenseReceipt::dispatch($company->id, $expense->id);

        return $expense->refresh();
    }

    /**
     * Approves the outlay and books it: the cost and input VAT against what
     * the company now owes the employee, with the receipt as underlag. The
     * person approving may correct date, text and amount, which is how an
     * outlay NONOX AI read wrongly, or could not read, is put right.
     *
     * @param  array<string, mixed>  $details  date, description, amount; blank keeps what is stored
     */
    public function approve(User $user, Expense $expense, int $accountNumber, ?string $vat = null, array $details = []): Expense
    {
        $company = $this->staff($user, $expense);

        return CurrentCompany::run($company, fn (): Expense => DB::transaction(function () use ($user, $company, $expense, $accountNumber, $vat, $details): Expense {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['expense' => __('Utlägget är redan besvarat.')]);
            }

            if ($expense->requested_by === $user->id && ($this->modules->settings(PayrollModule::KEY, $company)['segregation_of_duties'] ?? true)) {
                throw ValidationException::withMessages(['expense' => __('Du kan inte godkänna ditt eget utlägg. Någon annan med ansvar för lönerna behöver göra det.')]);
            }

            $corrected = $this->correct($expense, $details);

            if ($expense->amount_ore->ore <= 0) {
                throw ValidationException::withMessages(['amount' => __('Fyll i beloppet på kvittot innan utlägget godkänns.')]);
            }

            if (blank($expense->description)) {
                throw ValidationException::withMessages(['description' => __('Skriv vad utlägget gäller innan det godkänns.')]);
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
            Audit::record('payroll.expense_approved', $expense, ['voucher' => $voucher->reference(), 'corrected' => $corrected], $company, $user);

            return $expense;
        }));
    }

    /**
     * Applies the approver's corrections to the outlay (not saved here).
     *
     * @param  array<string, mixed>  $details
     * @return list<string> the fields that changed
     */
    private function correct(Expense $expense, array $details): array
    {
        $before = ['date' => $expense->date->toDateString(), 'description' => (string) $expense->description, 'amount' => $expense->amount_ore->ore];
        $date = trim((string) ($details['date'] ?? ''));
        $description = trim((string) ($details['description'] ?? ''));
        $amount = trim((string) ($details['amount'] ?? ''));

        if ($date !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || CarbonImmutable::parse($date)->greaterThan(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['date' => __('Datumet kan inte vara i framtiden.')]);
            }

            $expense->date = CarbonImmutable::parse($date);
        }

        if ($description !== '') {
            $expense->description = mb_substr($description, 0, 255);
        }

        if ($amount !== '') {
            try {
                $expense->amount_ore = Money::parse($amount);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['amount' => __('Beloppet går inte att läsa. Skriv till exempel 249 eller 249,50.')]);
            }
        }

        $after = ['date' => $expense->date->toDateString(), 'description' => (string) $expense->description, 'amount' => $expense->amount_ore->ore];

        return array_keys(array_diff_assoc($after, $before));
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
