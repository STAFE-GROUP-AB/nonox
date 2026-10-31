<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Livewire;

use App\Actions\Payables\SaveSupplier;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Modules\Inbox\Actions\ClaimUnassignedMessage;
use App\Modules\Inbox\Actions\InboxReview;
use App\Modules\Inbox\Actions\ReplyToSender;
use App\Modules\Inbox\Domain\FieldRole;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\UnassignedMessage;
use App\Modules\Inbox\Support\DailyReportVoucher;
use App\Modules\Inbox\Support\InboxAddress;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The inbox: tabs Att granska, Utkast klara, Bokfört and Avvisat; the
 * attachment on the left and the proposal on the right. Thin: every
 * decision is an action (ApproveInboxMessage, InboxReview,
 * ClaimUnassignedMessage, ReplyToSender).
 */
class InboxWorkspace extends Component
{
    use ShowsToasts;
    use WithPagination;

    #[Url(as: 'flik', except: 'att-granska')]
    public string $tab = 'att-granska';

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'id', except: '')]
    public string $selected = '';

    /** @var array<string, mixed> the proposal as the person edits it */
    public array $form = [];

    public bool $warningsConfirmed = false;

    public bool $confirmingApproval = false;

    public bool $confirmingRejection = false;

    public string $rejectionReason = '';

    public string $replyText = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', [InboxMessage::class, $this->company()]);

        if (! array_key_exists($this->tab, MessageStatus::tabs())) {
            $this->tab = 'att-granska';
        }

        if ($this->selected !== '') {
            $this->select($this->selected);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function select(string $id): void
    {
        $message = InboxMessage::query()->find($id);

        if (! $message instanceof InboxMessage) {
            $this->selected = '';

            return;
        }

        $this->selected = $message->id;
        $this->form = $this->editable((array) $message->proposal);
        $this->reset('warningsConfirmed', 'confirmingApproval', 'confirmingRejection', 'rejectionReason', 'replyText');
    }

    public function close(): void
    {
        $this->selected = '';
        $this->form = [];
    }

    /** Moves to the next (1) or previous (-1) message in the list. */
    public function step(int $direction): void
    {
        $ids = array_values($this->query()->limit(500)->pluck('id')->all());
        $index = array_search($this->selected, $ids, true);
        $next = $ids[$index === false ? 0 : max(0, min(count($ids) - 1, $index + $direction))] ?? null;

        if (is_string($next)) {
            $this->select($next);
        }
    }

    /**
     * Starts a proposal by hand for a message in the manual queue.
     */
    public function startAs(string $kind): void
    {
        $message = $this->message();
        $date = $message->received_at->toDateString();

        $this->form = match ($kind) {
            'supplier_invoice' => ['kind' => 'supplier_invoice', 'file_attachment_id' => $message->attachments->first(fn ($attachment): bool => $attachment->isPreviewable())?->id, 'invoice' => [
                'supplier_id' => null, 'supplier_name' => '', 'supplier_org_number' => '', 'supplier_vat_number' => '', 'supplier_email' => '', 'bankgiro' => '', 'plusgiro' => '', 'iban' => '', 'bic' => '',
                'invoice_number' => '', 'ocr' => '', 'invoice_date' => $date, 'due_date' => '', 'description' => (string) $message->subject, 'total' => '', 'vat' => '', 'credit' => false,
                'rows' => [['account' => '', 'amount' => '', 'description' => '', 'vat_rate' => 25]],
            ]],
            default => ['kind' => 'vouchers', 'vouchers' => [[
                'date' => $date, 'description' => mb_substr((string) ($message->subject ?? ''), 0, 255), 'series' => 'A',
                'rows' => [['account' => '', 'debit' => '', 'credit' => '', 'description' => ''], ['account' => '', 'debit' => '', 'credit' => '', 'description' => '']],
            ]]],
        };
    }

    public function addRow(int $voucher = 0): void
    {
        if (($this->form['kind'] ?? null) === 'supplier_invoice') {
            $this->form['invoice']['rows'][] = ['account' => '', 'amount' => '', 'description' => '', 'vat_rate' => 25];

            return;
        }

        $this->form['vouchers'][$voucher]['rows'][] = ['account' => '', 'debit' => '', 'credit' => '', 'description' => ''];
    }

    public function removeRow(int $row, int $voucher = 0): void
    {
        if (($this->form['kind'] ?? null) === 'supplier_invoice') {
            unset($this->form['invoice']['rows'][$row]);
            $this->form['invoice']['rows'] = array_values($this->form['invoice']['rows']);

            return;
        }

        unset($this->form['vouchers'][$voucher]['rows'][$row]);
        $this->form['vouchers'][$voucher]['rows'] = array_values($this->form['vouchers'][$voucher]['rows']);
    }

    public function approve(ApproveInboxMessage $approve, string $mode = ApproveInboxMessage::POST): void
    {
        $message = $this->message();

        if ($mode === ApproveInboxMessage::POST && ! $this->confirmingApproval) {
            $this->confirmingApproval = true;

            return;
        }

        $approved = $approve($this->user(), $message, $this->proposal(), $mode === ApproveInboxMessage::DRAFT ? ApproveInboxMessage::DRAFT : ApproveInboxMessage::POST, $this->warningsConfirmed);
        $this->confirmingApproval = false;

        $this->toast(match (true) {
            $mode === ApproveInboxMessage::DRAFT => __('Utkastet är skapat. Du hittar det bland verifikationerna eller leverantörsfakturorna.'),
            $approved->supplier_invoice_id !== null => __('Leverantörsfakturan är registrerad med e-posten som underlag.'),
            count($approved->voucher_ids) === 1 => __('Verifikationen är bokförd med e-posten som underlag.'),
            default => __(':count verifikationer är bokförda med e-posten som underlag.', ['count' => count($approved->voucher_ids)]),
        });
        $this->step(1);

        if ($this->selected === $approved->id) {
            $this->close();
        }
    }

    public function reject(InboxReview $review): void
    {
        if (! $this->confirmingRejection) {
            $this->confirmingRejection = true;

            return;
        }

        $review->reject($this->user(), $this->message(), $this->rejectionReason);
        $this->toast(__('Underlaget är avvisat. Det bokförs inte.'));
        $this->close();
    }

    public function allowSender(InboxReview $review): void
    {
        $message = $this->message();
        $released = $review->allowSender($this->user(), $message);
        $this->toast($released > 0
            ? __(':sender är godkänd. :count meddelanden tolkas nu.', ['sender' => $message->from_email, 'count' => $released])
            : __(':sender är godkänd som avsändare.', ['sender' => $message->from_email]));
        $this->select($message->id);
    }

    public function release(InboxReview $review): void
    {
        $review->releaseAfterReview($this->user(), $this->message());
        $this->toast(__('Meddelandet är släppt ur karantänen och tolkas nu. Det bokförs aldrig automatiskt.'));
        $this->select($this->selected);
    }

    public function reply(ReplyToSender $reply): void
    {
        $message = $this->message();
        $reply($this->user(), $message, $this->replyText);
        $this->replyText = '';
        $this->toast(__('Svaret är skickat till :sender.', ['sender' => $message->from_email]));
    }

    public function claim(ClaimUnassignedMessage $claim, string $id): void
    {
        $message = $claim($this->user(), $this->company(), $id);
        $this->toast(__('Meddelandet är hämtat till företagets inkorg.'));
        $this->select($message->id);
    }

    /**
     * Adds the invoice's supplier to the register, with the details the
     * invoice gives, and selects it.
     */
    public function createSupplier(SaveSupplier $save): void
    {
        $invoice = (array) ($this->form['invoice'] ?? []);
        $supplier = $save($this->user(), $this->company(), [
            'name' => (string) ($invoice['supplier_name'] ?? ''),
            'org_number' => ($invoice['supplier_org_number'] ?? '') ?: null,
            'vat_number' => ($invoice['supplier_vat_number'] ?? '') ?: null,
            'email' => ($invoice['supplier_email'] ?? '') ?: null,
            'bankgiro' => ($invoice['bankgiro'] ?? '') ?: null,
            'plusgiro' => ($invoice['plusgiro'] ?? '') ?: null,
            'iban' => ($invoice['iban'] ?? '') ?: null,
            'payment_terms_days' => 30,
        ]);

        $this->form['invoice']['supplier_id'] = $supplier->id;
        $this->toast(__(':name är tillagd i leverantörsregistret.', ['name' => $supplier->name]));
    }

    public function render(): View
    {
        $company = $this->company();
        $message = $this->selected === '' ? null : InboxMessage::query()->with(['attachments', 'template', 'handler'])->find($this->selected);

        return view('inbox::livewire.workspace', [
            'messages' => $this->query()->withCount('attachments')->paginate(20),
            'counts' => $this->counts(),
            'message' => $message,
            'unassigned' => UnassignedMessage::waitingFor($company)->latest('received_at')->limit(20)->get(),
            'preview' => $message !== null && ($this->form['kind'] ?? null) === 'daily_report' ? $this->dailyReportPreview($message) : null,
            'roles' => FieldRole::cases(),
            'suppliers' => ($this->form['kind'] ?? null) === 'supplier_invoice' ? Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'accounts' => $message !== null ? Account::query()->where('is_active', true)->orderBy('number')->get(['number', 'name']) : collect(),
            'canManage' => $this->user()->hasTeamPermission($company, 'inbox:manage'),
            'address' => InboxAddress::forSlug(InboxAddress::mailbox($company)->slug),
        ]);
    }

    /**
     * @return Builder<InboxMessage>
     */
    private function query(): Builder
    {
        $statuses = array_map(fn (MessageStatus $status): string => $status->value, MessageStatus::tabs()[$this->tab] ?? MessageStatus::tabs()['att-granska']);
        $search = trim($this->search);

        return InboxMessage::query()
            ->whereIn('status', $statuses)
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('from_email', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orWhere('subject', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->orderByDesc('received_at');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $byStatus = InboxMessage::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $counts = [];

        foreach (MessageStatus::tabs() as $tab => $statuses) {
            $counts[$tab] = (int) collect($statuses)->sum(fn (MessageStatus $status): int => (int) ($byStatus[$status->value] ?? 0));
        }

        return $counts;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, debit: int, credit: int, balanced: bool, vat: array<int, array{net: int, vat: int}>, warnings: list<array{code: string, message: string}>}
     */
    private function dailyReportPreview(InboxMessage $message): array
    {
        /** @var array<string, array{label: string, amount_ore: int}> $fields */
        $fields = (array) (((array) $message->proposal)['fields'] ?? []);
        $chart = array_values(Account::query()->where('is_active', true)->pluck('number')->map(fn (mixed $number): int => (int) $number)->all());

        return DailyReportVoucher::build($fields, $this->mapping(), $chart);
    }

    /**
     * The proposal from the form, with amounts in öre.
     *
     * @return array<string, mixed>
     */
    private function proposal(): array
    {
        $form = $this->form;
        $kind = (string) ($form['kind'] ?? '');

        if ($kind === 'daily_report') {
            return ['kind' => 'daily_report', 'date' => (string) ($form['date'] ?? ''), 'mapping' => $this->mapping()];
        }

        if ($kind === 'vouchers') {
            $form['vouchers'] = array_map(fn (mixed $voucher): array => [
                ...(array) $voucher,
                'rows' => array_values(array_map(fn (mixed $row): array => [
                    'account' => (int) (((array) $row)['account'] ?? 0),
                    'debit_ore' => $this->ore((string) (((array) $row)['debit'] ?? '')),
                    'credit_ore' => $this->ore((string) (((array) $row)['credit'] ?? '')),
                    'description' => (string) (((array) $row)['description'] ?? ''),
                ], array_filter((array) (((array) $voucher)['rows'] ?? []), fn (mixed $row): bool => trim((string) (((array) $row)['account'] ?? '')) !== ''))),
            ], (array) ($form['vouchers'] ?? []));
        }

        return $form;
    }

    /** @return array<string, array<string, mixed>> */
    private function mapping(): array
    {
        $mapping = [];

        // The form keeps the mapping as a list, since report keys hold spaces and dots.
        foreach ((array) ($this->form['mapping'] ?? []) as $entry) {
            $entry = (array) $entry;
            $key = (string) ($entry['key'] ?? '');
            unset($entry['key']);
            $role = FieldRole::tryFrom((string) ($entry['role'] ?? '')) ?? FieldRole::Ignore;
            $mapping[(string) $key] = [
                ...$entry,
                'role' => $role->value,
                'account' => $role === FieldRole::Ignore || trim((string) ($entry['account'] ?? '')) === '' ? null : (int) $entry['account'],
                'side' => in_array($entry['side'] ?? null, ['D', 'K'], true) ? $entry['side'] : $role->defaultSide(),
                'vat_rate' => ($entry['vat_rate'] ?? '') === '' || $entry['vat_rate'] === null ? null : (int) $entry['vat_rate'],
            ];
        }

        return $mapping;
    }

    /**
     * The stored proposal made editable: amounts as Swedish text, every
     * report field with an entry in the mapping.
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function editable(array $proposal): array
    {
        $kind = $proposal['kind'] ?? null;

        if ($kind === 'daily_report') {
            $mapping = [];

            foreach (array_keys((array) $proposal['fields']) as $key) {
                $entry = (array) (((array) $proposal['mapping'])[$key] ?? []);
                $mapping[] = [
                    'key' => (string) $key,
                    'role' => (string) ($entry['role'] ?? FieldRole::Ignore->value),
                    'account' => isset($entry['account']) ? (string) $entry['account'] : '',
                    'side' => (string) ($entry['side'] ?? 'K'),
                    'vat_rate' => isset($entry['vat_rate']) ? (string) $entry['vat_rate'] : '',
                    'reason' => (string) ($entry['reason'] ?? ''),
                    'confidence' => $entry['confidence'] ?? null,
                ];
            }

            return ['kind' => 'daily_report', 'date' => (string) ($proposal['date'] ?? ''), 'mapping' => $mapping, 'notes' => (string) ($proposal['notes'] ?? '')];
        }

        if ($kind === 'vouchers') {
            $proposal['vouchers'] = array_map(fn (mixed $voucher): array => [
                ...(array) $voucher,
                'rows' => array_map(fn (mixed $row): array => [
                    'account' => (string) (((array) $row)['account'] ?? ''),
                    'debit' => (int) (((array) $row)['debit_ore'] ?? 0) === 0 ? '' : Money::ofOre((int) ((array) $row)['debit_ore'])->format(withCurrency: false),
                    'credit' => (int) (((array) $row)['credit_ore'] ?? 0) === 0 ? '' : Money::ofOre((int) ((array) $row)['credit_ore'])->format(withCurrency: false),
                    'description' => (string) (((array) $row)['description'] ?? ''),
                ], (array) (((array) $voucher)['rows'] ?? [])),
            ], (array) ($proposal['vouchers'] ?? []));
        }

        return $proposal;
    }

    private function ore(string $amount): int
    {
        if (trim($amount) === '') {
            return 0;
        }

        try {
            return Money::parse($amount)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['form' => __('Beloppet :amount går inte att läsa. Skriv till exempel 1 249,00.', ['amount' => $amount])]);
        }
    }

    private function message(): InboxMessage
    {
        $message = InboxMessage::query()->with('attachments')->findOrFail($this->selected);
        Gate::authorize('manage', $message);

        return $message;
    }

    private function company(): Company
    {
        return $this->user()->currentCompany() ?? abort(404);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
