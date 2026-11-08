<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Modules\Crm\Actions\CompleteActivity;
use App\Modules\Crm\Actions\CreateQuoteForDeal;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\MoveDeal;
use App\Modules\Crm\Actions\SaveDeal;
use App\Modules\Crm\Actions\SetWinBack;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Modules\Crm\Domain\DealNextStep;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\DealStageChange;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Money\Money;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A deal's card (ADR-043 stage 2): the account, value, stage, close date
 * and owner; the stages to move it along; its next steps, where the last
 * open one is completed only together with the next (or by winning or
 * losing the deal); Vunnen with the quote, order or invoice it led to;
 * Förlorad with a reason and a date to win the customer back; and the
 * history of stage moves and completed steps. Phone first.
 */
class DealCard extends Component
{
    use ActsInCompany;
    use ShowsToasts;

    #[Locked]
    public int $dealId;

    /** The open panel: edit, step, complete, win, lose or winback; '' for none. */
    public string $panel = '';

    /** @var array<string, string> */
    public array $edit = [];

    /** @var array<string, string> a new step, or the next step asked for when completing the last one */
    public array $step = [];

    #[Locked]
    public ?int $completingId = null;

    /** @var array<string, string> */
    public array $completion = ['call_outcome' => '', 'note' => ''];

    /** Whether the completed activity is the deal's last open step: then the next one is required. */
    public bool $nextRequired = false;

    /** @var array<string, mixed> */
    public array $win = [];

    /** @var array<string, string> */
    public array $lose = [];

    public string $winBackAt = '';

    public function mount(int $dealId): void
    {
        $deal = Deal::query()->findOrFail($dealId);
        abort_unless($this->user()->can('view', $deal), 403);

        $this->dealId = $deal->id;
    }

    public function moveTo(int $stageId, MoveDeal $move): void
    {
        try {
            $deal = $move($this->user(), $this->company(), $this->deal(), $stageId);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Affären är flyttad till :stage.', ['stage' => PipelineStage::query()->find($deal->stage_id)?->name]));
    }

    public function startEdit(): void
    {
        $deal = $this->authorizedDeal();

        $this->edit = [
            'title' => $deal->title,
            'value' => $deal->value()->format(withCurrency: false),
            'expected_close_date' => (string) $deal->expected_close_date?->toDateString(),
            'owner_id' => (string) $deal->owner_id,
            'person_id' => (string) $deal->person_id,
        ];
        $this->open('edit');
    }

    public function saveEdit(SaveDeal $save): void
    {
        $save($this->user(), $this->company(), [
            'title' => $this->edit['title'],
            'value' => $this->edit['value'],
            'expected_close_date' => $this->edit['expected_close_date'],
            'owner_id' => $this->edit['owner_id'] === '' ? null : (int) $this->edit['owner_id'],
            'person_id' => $this->edit['person_id'] === '' ? null : (int) $this->edit['person_id'],
        ], $this->deal());

        $this->closePanel();
        $this->toast(__('Affären är sparad.'));
    }

    /** A further step on the deal, besides the ones planned. */
    public function startStep(): void
    {
        $deal = $this->authorizedDeal();

        $this->step = $this->blankStep($deal);
        $this->open('step');
    }

    public function saveStep(LogActivity $log): void
    {
        $deal = $this->deal();

        $log($this->user(), $this->company(), [
            ...$this->stepInput(),
            'account_id' => $deal->account_id,
            // A contact person marked Kontakta inte stays on the deal; the step is planned without them.
            'person_id' => $deal->person_id !== null && Person::query()->whereKey($deal->person_id)->whereNull('do_not_contact_at')->exists() ? $deal->person_id : null,
            'deal_id' => $deal->id,
            'done' => false,
        ]);

        $this->closePanel();
        $this->toast(__('Nästa steg är planerat.'));
    }

    /** Klar: the outcome and a note, and the next step when this is the deal's last open one. */
    public function startCompletion(int $activityId): void
    {
        $deal = $this->authorizedDeal();
        $activity = $this->steps()->whereNull('done_at')->findOrFail($activityId);

        $this->completingId = $activity->id;
        $this->completion = ['call_outcome' => $activity->type === ActivityType::Call ? CallOutcome::Reached->value : '', 'note' => ''];
        $this->nextRequired = DealNextStep::isLastStep($activity);
        $this->step = $this->blankStep($deal);
        $this->open('complete');
    }

    public function complete(CompleteActivity $complete): void
    {
        $activity = $this->steps()->findOrFail((int) $this->completingId);

        $complete($this->user(), $this->company(), $activity, array_filter([
            'call_outcome' => $this->completion['call_outcome'] === '' ? null : $this->completion['call_outcome'],
            'note' => $this->completion['note'],
            'next' => $this->nextRequired ? $this->stepInput() : null,
        ], fn (mixed $value): bool => $value !== null));

        $this->closePanel();
        $this->toast($this->nextRequired ? __('Klart, och nästa steg är planerat.') : __('Klart.'));
    }

    /** Ny offert: one row from the deal, linked to it, opened in the quote editor. */
    public function newQuote(CreateQuoteForDeal $create): void
    {
        $deal = $this->authorizedDeal();

        try {
            $quote = $create($this->user(), $this->company(), $deal);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        Toast::flash(__(':title är skapad från affären. Kontrollera raderna och skicka den.', ['title' => $quote->title()]));
        $this->redirectRoute('invoicing.quotes.show', ['document' => $quote->id]);
    }

    public function startWin(): void
    {
        $deal = $this->authorizedDeal();

        $this->win = ['value' => $deal->value()->format(withCurrency: false), 'quote_id' => (string) $deal->quote_id, 'order_id' => (string) $deal->order_id, 'invoice_id' => (string) $deal->invoice_id, 'make_customer' => true];
        $this->open('win');
    }

    public function saveWin(WinDeal $win): void
    {
        try {
            $value = trim((string) $this->win['value']) === '' ? 0 : Money::parse((string) $this->win['value'])->ore;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['value_ore' => __('Skriv värdet i kronor, till exempel 12 500.')]);
        }

        $deal = $win($this->user(), $this->company(), $this->deal(), [
            'value_ore' => $value,
            'quote_id' => $this->win['quote_id'] === '' ? null : $this->win['quote_id'],
            'order_id' => $this->win['order_id'] === '' ? null : $this->win['order_id'],
            'invoice_id' => $this->win['invoice_id'] === '' ? null : $this->win['invoice_id'],
            'make_customer' => (bool) $this->win['make_customer'],
        ]);

        $this->closePanel();
        $this->toast(__('Grattis! :title är vunnen.', ['title' => $deal->title]));
    }

    public function startLose(): void
    {
        $this->authorizedDeal();

        $this->lose = [
            'loss_reason_id' => '',
            'loss_note' => '',
            'win_back_at' => CarbonImmutable::now(LogActivity::ZONE)->addMonthsNoOverflow(CrmSettings::for($this->company())['win_back_months'])->toDateString(),
        ];
        $this->open('lose');
    }

    public function saveLose(LoseDeal $lose): void
    {
        $deal = $lose($this->user(), $this->company(), $this->deal(), [
            'loss_reason_id' => $this->lose['loss_reason_id'] === '' ? null : (int) $this->lose['loss_reason_id'],
            'loss_note' => $this->lose['loss_note'],
            'win_back_at' => $this->lose['win_back_at'],
        ]);

        $this->closePanel();
        $this->toast($deal->win_back_at === null ? __('Affären är förlorad.') : __('Affären är förlorad. Du får en påminnelse att vinna tillbaka kunden den :date.', ['date' => $deal->win_back_at->translatedFormat('j F Y')]));
    }

    public function startWinBack(): void
    {
        $deal = $this->authorizedDeal(open: false);

        $this->winBackAt = (string) $deal->win_back_at?->toDateString();
        $this->open('winback');
    }

    public function saveWinBack(SetWinBack $set): void
    {
        $date = $this->winBackAt;
        $set($this->user(), $this->company(), $this->deal(), $date === '' ? null : $date);

        $this->closePanel();
        $this->toast($date === '' ? __('Ingen påminnelse om att vinna tillbaka kunden.') : __('Påminnelsen är flyttad till :date.', ['date' => CarbonImmutable::parse($date)->translatedFormat('j F Y')]));
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'edit', 'step', 'completingId', 'completion', 'nextRequired', 'win', 'lose', 'winBackAt');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $deal = Deal::query()
            ->with(['account:id,name,customer_id,kind', 'person:id,name,do_not_contact_at', 'owner:id,name', 'stage:id,name,probability', 'pipeline:id,name', 'lossReason:id,name', 'quote:id,kind,number', 'order:id,kind,number', 'invoice:id,number'])
            ->findOrFail($this->dealId);
        $company = $this->company();
        $user = $this->user();
        $customerId = $deal->account?->customer_id;
        $canSeeInvoices = $user->hasTeamPermission($company, 'invoices:view');

        return view('crm::livewire.deal-card', [
            'deal' => $deal,
            'stages' => PipelineStage::query()->where('pipeline_id', $deal->pipeline_id)->orderBy('sort')->orderBy('id')->get(),
            'open' => $this->steps()->whereNull('done_at')->with(['person:id,name', 'assignee:id,name'])->orderByRaw('due_at ASC NULLS LAST')->orderBy('id')->get(),
            'history' => $this->history($deal),
            'canManage' => $user->can('update', $deal),
            'canSeeInvoices' => $canSeeInvoices,
            // As on the account card: a private person's quotes, orders and invoices are for those who may see the invoices.
            'limitedLedger' => ! $canSeeInvoices && $deal->account?->kind === AccountKind::Person,
            'canMakeCustomer' => $customerId === null && $user->can('create', [Customer::class, $company]),
            // "Ny offert" and "Ny offert från mall" (ADR-046) for an open deal, for those who make quotes; a prospect is made a customer by it, if the user may add customers.
            'canMakeQuote' => $deal->isOpen() && $user->hasTeamPermission($company, 'invoices:create') && ($customerId !== null || $user->can('create', [Customer::class, $company])),
            'members' => in_array($this->panel, ['edit', 'step', 'complete'], true) ? CompanyMembers::all($company) : collect(),
            'people' => $this->panel === 'edit' ? Person::query()->whereHas('accounts', fn (Builder $query) => $query->whereKey($deal->account_id))->orderBy('name')->get(['id', 'name']) : collect(),
            'reasons' => $this->panel === 'lose' ? LossReason::query()->active()->orderBy('sort')->orderBy('id')->get(['id', 'name']) : collect(),
            // Only a customer has quotes, orders and invoices; a seller without invoices:view picks none.
            'quotes' => $this->panel === 'win' && $customerId !== null && $canSeeInvoices ? $this->documents($customerId, SalesDocument::QUOTE) : collect(),
            'orders' => $this->panel === 'win' && $customerId !== null && $canSeeInvoices ? $this->documents($customerId, SalesDocument::ORDER) : collect(),
            'invoices' => $this->panel === 'win' && $customerId !== null && $canSeeInvoices
                ? Invoice::query()->where('customer_id', $customerId)->where('kind', Invoice::INVOICE)->whereNotNull('issued_at')->orderByDesc('invoice_date')->limit(20)->get(['id', 'number', 'invoice_date', 'net_ore'])
                : collect(),
            'types' => array_filter(ActivityType::cases(), fn (ActivityType $type): bool => $type->canBePlanned()),
            'outcomes' => CallOutcome::cases(),
            'now' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Stage moves and completed steps, newest first.
     *
     * @return Collection<int, array{at: CarbonImmutable, kind: string, text: string, user: ?string}>
     */
    private function history(Deal $deal): Collection
    {
        $changes = DealStageChange::query()
            ->where('deal_id', $deal->id)
            ->with(['fromStage:id,name', 'toStage:id,name', 'user:id,name'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        // The deal's first row is its creation; a later row without a from stage is a move from a stage since removed.
        $first = $changes->min('id');
        $moves = $changes
            ->map(fn (DealStageChange $change): array => [
                'at' => $change->changed_at,
                'kind' => 'stage',
                'text' => (string) ($change->id === $first && $change->from_stage_id === null
                    ? __('Skapad i :stage', ['stage' => $change->toStage->name ?? __('ett borttaget steg')])
                    : __('Flyttad från :from till :to', ['from' => $change->fromStage->name ?? __('ett borttaget steg'), 'to' => $change->toStage->name ?? __('ett borttaget steg')])),
                'user' => $change->user?->name,
            ]);

        $done = $this->steps()
            ->whereNotNull('done_at')
            ->with('assignee:id,name')
            ->orderByDesc('done_at')
            ->limit(50)
            ->get()
            ->map(fn (Activity $activity): array => [
                'at' => $activity->done_at ?? CarbonImmutable::now(),
                'kind' => 'activity',
                'text' => $activity->type->label().' · '.$activity->subject.($activity->call_outcome ? ' · '.$activity->call_outcome->label() : ''),
                'user' => $activity->assignee?->name,
            ]);

        return $moves->concat($done)->sortByDesc(fn (array $entry): int => $entry['at']->getTimestamp())->values();
    }

    /** @return Collection<int, SalesDocument> */
    private function documents(int $customerId, string $kind): Collection
    {
        return SalesDocument::query()->where('customer_id', $customerId)->where('kind', $kind)->orderByDesc('number')->limit(20)->get(['id', 'kind', 'number', 'document_date', 'net_ore']);
    }

    /** @return array<string, string> */
    private function blankStep(Deal $deal): array
    {
        return [
            'type' => ActivityType::Call->value,
            'subject' => '',
            'due_at' => CarbonImmutable::now(LogActivity::ZONE)->addWeekday()->setTime(9, 0)->format('Y-m-d\TH:i'),
            'assigned_to' => (string) ($deal->owner_id ?? $this->user()->id),
        ];
    }

    /** @return array<string, mixed> */
    private function stepInput(): array
    {
        return [
            'type' => $this->step['type'] ?? ActivityType::Task->value,
            'subject' => $this->step['subject'] ?? '',
            'due_at' => $this->step['due_at'] ?? null,
            'assigned_to' => ($this->step['assigned_to'] ?? '') === '' ? null : (int) $this->step['assigned_to'],
        ];
    }

    private function open(string $panel): void
    {
        $this->panel = $panel;
        $this->resetErrorBag();
    }

    /** @return Builder<Activity> */
    private function steps(): Builder
    {
        return Activity::query()->where('deal_id', $this->dealId);
    }

    private function deal(): Deal
    {
        return Deal::query()->findOrFail($this->dealId);
    }

    private function authorizedDeal(bool $open = true): Deal
    {
        $deal = $this->deal();
        abort_unless($this->user()->can('update', $deal), 403);

        if ($open && ! $deal->isOpen()) {
            abort(404);
        }

        return $deal;
    }
}
