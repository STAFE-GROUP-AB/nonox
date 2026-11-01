<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\RemoveAttachment;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Assets\AssetProposals;
use App\Domain\Accounting\AccountBalances;
use App\Domain\Accounting\VoucherTemplate;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Payables\SupplierInvoiceEditor;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherRow;
use App\Models\VoucherSeries;
use App\Modules\Ai\Actions\AnalyzeDocument;
use App\Modules\Ai\Support\AiAnalysisException;
use App\Support\Files\PrivateFile;
use App\Support\Money\Money;
use App\Support\Toast;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Keyboard-first voucher entry (AGENTS.md §11.2). The balance is calculated
 * live in the browser; every rule is enforced again on the server by
 * SaveDraftVoucher and PostVoucher.
 */
class VoucherEditor extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    #[Locked]
    public ?string $voucherId = null;

    public string $voucherDate = '';

    public string $description = '';

    public string $series = 'A';

    /**
     * Comes from the browser, so its shape is checked when the rows are read.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /**
     * Files chosen before the voucher has been saved. They are attached the
     * first time it is saved or posted.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $uploads = [];

    public string $template = '';

    /** @var ?array{notes: string, confidence: ?float, supplier: ?string, supplier_invoice?: bool} what NONOX AI proposed, for the person to check */
    public ?array $aiResult = null;

    public string $templateAmount = '';

    /**
     * The posted balance of each account at the voucher date (account number
     * => öre, debit positive), for Kontosaldo; null when the date is outside
     * every fiscal year. Set by the server only.
     *
     * @var ?array<int, int>
     */
    #[Locked]
    public ?array $balances = null;

    public function mount(?Voucher $voucher = null, ?Voucher $copyOf = null): void
    {
        if ($voucher instanceof Voucher) {
            abort_unless(Gate::allows('update', $voucher), 403);

            $this->voucherId = $voucher->id;
            $this->fillFrom($voucher);
            $this->rows = $this->withAssetProposals($this->rows, $voucher);
            $this->refreshBalances();

            return;
        }

        abort_unless(Gate::allows('create', [Voucher::class, $this->company()]), 403);

        $this->voucherDate = now()->toDateString();

        if ($copyOf instanceof Voucher) {
            $this->fillFrom($copyOf);
            $this->voucherDate = now()->toDateString();
            $this->description = mb_substr(__('Kopia av :reference: :description', [
                'reference' => $copyOf->reference(),
                'description' => $copyOf->description,
            ]), 0, 255);
            $this->refreshBalances();

            return;
        }

        $this->rows = [$this->blankRow(), $this->blankRow()];
        $this->refreshBalances();
    }

    /**
     * A new date gives other balances. Only the balances change, so nothing
     * is rendered again.
     */
    public function updatedVoucherDate(): void
    {
        $this->refreshBalances();
        $this->skipRender();
    }

    /**
     * Fills the rows from a konteringsmall (AGENTS.md §11.2).
     */
    public function applyTemplate(): void
    {
        $this->resetErrorBag(['template', 'templateAmount']);

        $template = VoucherTemplate::tryFrom($this->template);

        if ($template === null) {
            $this->addError('template', __('Välj en mall.'));

            return;
        }

        try {
            $total = Money::parse($this->templateAmount);
        } catch (InvalidArgumentException) {
            $this->addError('templateAmount', __('Beloppet går inte att läsa. Skriv till exempel 1 250,00.'));

            return;
        }

        if ($total->ore <= 0) {
            $this->addError('templateAmount', __('Skriv ett belopp större än noll.'));

            return;
        }

        $rows = $template->rows($total);
        $names = Account::query()
            ->whereIn('number', array_filter(array_column($rows, 'account')))
            ->pluck('name', 'number');

        $this->rows = [
            ...array_map(fn (array $row): array => [
                'account' => $row['account'] === null ? '' : trim($row['account'].' '.($names[$row['account']] ?? '')),
                'debit' => $row['debit_ore'] > 0 ? Money::ofOre($row['debit_ore'])->format(withCurrency: false) : '',
                'credit' => $row['credit_ore'] > 0 ? Money::ofOre($row['credit_ore'])->format(withCurrency: false) : '',
                'description' => '',
                'cost_center' => '',
                'project' => '',
            ], $rows),
            $this->blankRow(),
        ];

        if (trim($this->description) === '') {
            $this->description = $template->label();
        }

        $this->reset('template', 'templateAmount');
        $this->toast(
            in_array(null, array_column($rows, 'account'), true)
                ? __('Raderna är ifyllda. Välj kostnadskonto på den första raden.')
                : __('Raderna är ifyllda.'),
        );
    }

    /**
     * On a saved draft, a chosen file is attached right away.
     */
    public function updatedUploads(AttachToVoucher $attach): void
    {
        if ($this->voucherId !== null && ! $this->attachUploads($attach, Voucher::query()->findOrFail($this->voucherId))) {
            return;
        }

        $this->analyzeAutomatically();
    }

    /**
     * Lets NONOX AI read the newest document and propose the voucher. The
     * rows are filled in for the person to check; nothing is saved or posted.
     */
    public function analyze(AnalyzeDocument $analyze): void
    {
        $upload = $this->uploads === [] ? null : end($this->uploads);
        $attachment = $upload === null && $this->voucherId !== null
            ? VoucherAttachment::query()->where('voucher_id', $this->voucherId)->latest('id')->first()
            : null;
        [$path, $mime] = match (true) {
            $upload instanceof TemporaryUploadedFile => [(string) $upload->getRealPath(), (string) $upload->getMimeType()],
            $attachment instanceof VoucherAttachment => [PrivateFile::temporaryCopy(AttachToVoucher::DISK, $attachment->file_path), $attachment->mime],
            default => [null, null],
        };

        if ($path === null) {
            $this->toast(__('Dra först in underlaget som PDF eller bild.'), 'error');

            return;
        }

        try {
            $answer = $analyze->voucher($this->user(), $this->company(), $path, (string) $mime);
        } catch (AiAnalysisException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $amount = fn (mixed $value): string => (float) $value === 0.0 ? '' : Money::parse((string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp))->format(withCurrency: false);

        $this->voucherDate = (string) ($answer['date'] ?: $this->voucherDate);
        $this->refreshBalances();
        $this->description = (string) ($answer['description'] ?? $this->description);
        $this->rows = array_values([...array_map(fn (mixed $row): array => [
            ...$this->blankRow(),
            'account' => (string) ((array) $row)['account'],
            'debit' => $amount(((array) $row)['debit'] ?? 0),
            'credit' => $amount(((array) $row)['credit'] ?? 0),
            'description' => (string) (((array) $row)['description'] ?? ''),
        ], (array) $answer['rows']), $this->blankRow()]);
        $this->aiResult = [
            'notes' => (string) ($answer['notes'] ?? ''),
            'confidence' => null,
            'supplier' => null,
            // An unpaid supplier invoice belongs in the payables, where it gets a supplier, a due date and a payment.
            'supplier_invoice' => ($answer['document_type'] ?? null) === 'SUPPLIER_INVOICE'
                && $upload instanceof TemporaryUploadedFile
                && $this->user()->can('create', [SupplierInvoice::class, $this->company()]),
        ];
        $this->toast(__('NONOX AI har föreslagit verifikationen. Kontrollera konton och belopp innan du sparar eller bokför.'));
    }

    /**
     * Takes the document NONOX AI recognised as an unpaid supplier invoice to
     * the supplier invoice page, where it is read again as an invoice and the
     * supplier can be created. Only the uploaded file moves; nothing is saved.
     */
    public function toSupplierInvoice(): void
    {
        $upload = $this->uploads === [] ? null : end($this->uploads);

        if (! $upload instanceof TemporaryUploadedFile) {
            return;
        }

        session()->put(SupplierInvoiceEditor::HANDOFF, ['file' => $upload->getFilename(), 'user' => $this->user()->id, 'company' => $this->company()->id]);
        $this->redirectRoute('payables.invoices.create');
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function removeAttachment(int $attachmentId, RemoveAttachment $removeAttachment): void
    {
        $attachment = VoucherAttachment::query()->where('voucher_id', $this->voucherId)->findOrFail($attachmentId);

        $removeAttachment($this->user(), $attachment);
        $this->toast(__('Underlaget :name är borttaget.', ['name' => $attachment->original_name]));
    }

    public function saveDraft(SaveDraftVoucher $saveDraft): void
    {
        $voucher = $this->persist($saveDraft);

        if ($voucher === null) {
            return;
        }

        if ($this->voucherId === null) {
            Toast::flash(__('Utkastet är sparat. Du kan fortsätta senare.'));
            $this->redirectRoute('accounting.vouchers.edit', ['voucher' => $voucher->id]);

            return;
        }

        $this->toast(__('Utkastet är sparat.'));
    }

    public function post(SaveDraftVoucher $saveDraft, PostVoucher $postVoucher): void
    {
        $voucher = $this->persist($saveDraft);

        if ($voucher === null) {
            return;
        }

        $this->voucherId = $voucher->id;

        try {
            $posted = $postVoucher($this->user(), $voucher);
        } catch (ValidationException $exception) {
            $this->addErrorsFrom($exception, $this->rowInput());

            return;
        }

        Toast::flash(__(':reference är bokförd.', ['reference' => $posted->reference()]));
        $this->redirectRoute('accounting.vouchers.show', ['voucher' => $posted->id]);
    }

    public function render(): View
    {
        $costCenters = CostCenter::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']);
        $accounts = Account::query()->where('is_active', true)->orderBy('number')->get(['number', 'name', 'type']);
        $projects = Project::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']);

        return view('livewire.accounting.voucher-editor', [
            'costCenters' => $costCenters,
            'projects' => $projects,
            'showCostCenters' => $costCenters->isNotEmpty() || $this->rowsUse('cost_center'),
            'showProjects' => $projects->isNotEmpty() || $this->rowsUse('project'),
            'templates' => VoucherTemplate::cases(),
            'attachments' => $this->voucherId === null
                ? collect()
                : VoucherAttachment::query()->where('voucher_id', $this->voucherId)->orderBy('id')->get(),
            'accounts' => $accounts,
            'accountSigns' => $accounts->mapWithKeys(fn (Account $account): array => [$account->number => AccountBalances::sign($account->type)])->all(),
            'seriesOptions' => VoucherSeries::query()->orderBy('code')->get(['code', 'name']),
            'aiAvailable' => AnalyzeDocument::availableFor($this->user(), $this->company()),
            'canPost' => $this->user()->hasTeamPermission($this->company(), 'vouchers:post'),
            'assetSettings' => $this->managesAssets() ? AssetProposals::editorSettings($this->company()) : null,
        ]);
    }

    /** Whether this person keeps the fixed asset register, and so may create assets from the rows. */
    private function managesAssets(): bool
    {
        return $this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage');
    }

    /**
     * Puts a draft's asset proposals back on its rows (row.asset), counted
     * among the rows with content, as they were saved.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withAssetProposals(array $rows, Voucher $voucher): array
    {
        if (! $this->managesAssets()) {
            return $rows;
        }

        $proposals = AssetProposals::forms($voucher);

        foreach ($rows as $index => $row) {
            if (isset($proposals[$index])) {
                $rows[$index]['asset'] = $proposals[$index];
            }
        }

        return $rows;
    }

    /**
     * Kontosaldo: the posted balances at the voucher date, in one query for
     * every account, so choosing an account needs no request.
     */
    private function refreshBalances(): void
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->voucherDate) === 1 && checkdate((int) substr($this->voucherDate, 5, 2), (int) substr($this->voucherDate, 8, 2), (int) substr($this->voucherDate, 0, 4))
            ? CarbonImmutable::parse($this->voucherDate)
            : null;
        $fiscalYear = $date === null
            ? null
            : FiscalYear::query()->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();

        $this->balances = $fiscalYear === null ? null : AccountBalances::atDate($fiscalYear->id, $date)->all();
    }

    /**
     * When the company has NONOX AI and the person may use it, a new
     * document is read at once (the browser runs analyze() with its
     * countdown). The AI only fills in the form; nothing is saved or posted
     * (ADR-018). The Analysera button stays for a second try.
     */
    private function analyzeAutomatically(): void
    {
        if ($this->getErrorBag()->has('uploads') || ! AnalyzeDocument::availableFor($this->user(), $this->company())) {
            return;
        }

        $this->dispatch(AnalyzeDocument::AUTO_EVENT);
    }

    private function persist(SaveDraftVoucher $saveDraft): ?Voucher
    {
        $this->resetErrorBag();

        try {
            $rows = $this->rowInput();

            $voucher = $saveDraft($this->user(), $this->company(), [
                'voucher_date' => $this->voucherDate,
                'description' => $this->description,
                'series' => $this->series,
                'rows' => array_column($rows, 'input'),
                'with_assets' => $this->managesAssets(),
            ], $this->voucherId === null ? null : Voucher::query()->findOrFail($this->voucherId));
        } catch (ValidationException $exception) {
            $this->addErrorsFrom($exception, $rows ?? []);

            return null;
        }

        return $this->attachUploads(app(AttachToVoucher::class), $voucher) ? $voucher : null;
    }

    /**
     * Attaches the chosen files. Returns false and shows why when a file is
     * refused, so nothing is posted with a missing receipt.
     */
    private function attachUploads(AttachToVoucher $attach, Voucher $voucher): bool
    {
        foreach ($this->uploads as $index => $upload) {
            try {
                $attachment = $attach($this->user(), $voucher, $upload);
            } catch (ValidationException $exception) {
                $this->addError('uploads', __(':name: :reason', [
                    'name' => $upload->getClientOriginalName(),
                    'reason' => collect($exception->errors())->flatten()->first(),
                ]));

                return false;
            }

            unset($this->uploads[$index]);

            if ($this->voucherId !== null) {
                $this->toast(__('Underlaget :name är sparat.', ['name' => $attachment->original_name]));
            }
        }

        $this->uploads = [];

        return true;
    }

    /**
     * The rows that have content, with the index they have in the form, so
     * error messages land on the right line.
     *
     * @return list<array{line: int, input: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    private function rowInput(): array
    {
        $result = [];
        $errors = [];

        foreach ($this->rows as $index => $row) {
            $account = trim((string) ($row['account'] ?? ''));
            $debit = trim((string) ($row['debit'] ?? ''));
            $credit = trim((string) ($row['credit'] ?? ''));

            if ($account === '' && $debit === '' && $credit === '') {
                continue;
            }

            try {
                $result[] = ['line' => $index, 'input' => [
                    'account' => preg_match('/^\s*(\d{4})/', $account, $match) ? (int) $match[1] : 0,
                    'debit_ore' => $debit === '' ? 0 : Money::parse($debit)->ore,
                    'credit_ore' => $credit === '' ? 0 : Money::parse($credit)->ore,
                    'description' => trim((string) ($row['description'] ?? '')),
                    'cost_center' => $this->leadingCode($row['cost_center'] ?? ''),
                    'project' => $this->leadingCode($row['project'] ?? ''),
                    'asset' => is_array($row['asset'] ?? null) ? $row['asset'] : null,
                ]];
            } catch (InvalidArgumentException) {
                $errors["rows.{$index}.amount"] = __('Rad :line: beloppet går inte att läsa. Skriv till exempel 1 234,50.', ['line' => $index + 1]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    /**
     * "10 Restaurang Malmö" as chosen in the list, or just "10", gives "10".
     */
    private function leadingCode(mixed $value): string
    {
        return (string) strtok(trim((string) $value), ' ');
    }

    private function rowsUse(string $key): bool
    {
        foreach ($this->rows as $row) {
            if (trim((string) ($row[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{line: int, input: array<string, mixed>}>  $rows
     */
    private function addErrorsFrom(ValidationException $exception, array $rows = []): void
    {
        foreach ($exception->errors() as $key => $messages) {
            if (preg_match('/^rows\.(\d+)\.(\w+)$/', $key, $match) && isset($rows[(int) $match[1]])) {
                $line = $rows[(int) $match[1]]['line'];
                $key = "rows.{$line}.{$match[2]}";
                $messages = array_map(
                    fn (string $message): string => preg_replace('/^Rad \d+:/', 'Rad '.($line + 1).':', $message) ?? $message,
                    $messages,
                );
            }

            foreach ($messages as $message) {
                $this->addError($key, $message);
            }
        }
    }

    private function fillFrom(Voucher $voucher): void
    {
        $voucher->loadMissing(['rows.account', 'rows.costCenter', 'rows.project', 'series']);

        $this->voucherDate = $voucher->voucher_date->toDateString();
        $this->description = $voucher->description;
        $this->series = $voucher->series->code ?? 'A';
        $this->rows = [
            ...$voucher->rows->map(fn (VoucherRow $row): array => [
                'account' => $row->account ? "{$row->account->number} {$row->account->name}" : '',
                'debit' => $row->debit_ore->isZero() ? '' : $row->debit_ore->format(withCurrency: false),
                'credit' => $row->credit_ore->isZero() ? '' : $row->credit_ore->format(withCurrency: false),
                'description' => (string) $row->description,
                'cost_center' => (string) $row->costCenter?->label(),
                'project' => (string) $row->project?->label(),
            ])->all(),
            $this->blankRow(),
        ];
    }

    /** @return array{account: string, debit: string, credit: string, description: string, cost_center: string, project: string} */
    private function blankRow(): array
    {
        return ['account' => '', 'debit' => '', 'credit' => '', 'description' => '', 'cost_center' => '', 'project' => ''];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
