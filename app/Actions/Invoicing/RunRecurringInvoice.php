<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Tasks\SaveTask;
use App\Domain\Invoicing\AgreementStatus;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\RecurringPriceMode;
use App\Domain\Tasks\TaskPriority;
use App\Domain\Tasks\TaskType;
use App\Events\RecurringInvoiceStatusChanged;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRow;
use App\Models\Task;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\SendInvoiceViaPeppol;
use App\Modules\Peppol\PeppolModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Makes the agreement's next invoice, dated on its next invoice date, and
 * moves that date one interval on. The invoice is a draft to check, or, if
 * the agreement says so, issued (booked) and emailed to the customer. Past
 * the end date the agreement ends. An agreement that follows the price lists
 * (price_mode FOLLOW_PRICES, "Följ prislistan") prices its article rows for
 * the customer on the run date through the price engine, channel RECURRING,
 * and stores the source and explanation on the invoice rows.
 */
class RunRecurringInvoice
{
    public function __construct(
        private readonly SaveDraftInvoice $saveDraft,
        private readonly IssueInvoice $issue,
        private readonly SendInvoice $send,
    ) {}

    public function __invoke(User $user, RecurringInvoice $agreement, ?CarbonImmutable $today = null): Invoice
    {
        Gate::forUser($user)->authorize('update', $agreement);

        $today ??= CarbonImmutable::today();

        $invoice = CurrentCompany::run($agreement->company_id, fn (): Invoice => DB::transaction(function () use ($user, $agreement, $today): Invoice {
            /** @var RecurringInvoice $agreement */
            $agreement = RecurringInvoice::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $company = Company::query()->findOrFail($agreement->company_id);

            if (! $agreement->isDue($today)) {
                throw ValidationException::withMessages(['agreement' => __(':title ska inte faktureras än. Nästa faktura: :date.', ['title' => $agreement->title(), 'date' => $agreement->next_invoice_date->toDateString()])]);
            }

            $date = $agreement->next_invoice_date;
            $follows = $agreement->price_mode === RecurringPriceMode::FollowPrices;
            $agreementRows = $agreement->rows()->with($follows ? ['account', 'article'] : ['account'])->get();
            $invoice = ($this->saveDraft)($user, $company, [
                'customer_id' => $agreement->customer_id,
                'invoice_date' => $date->toDateString(),
                'your_reference' => $agreement->your_reference,
                'our_reference' => $agreement->our_reference,
                'notes' => $agreement->notes,
                'rows' => $agreementRows->map(fn (RecurringInvoiceRow $row): array => $follows ? self::followingRow($row) : $row->asInput())->all(),
                ...($follows ? ['price_channel' => PriceChannel::Recurring->value] : []),
            ]);
            $invoice->forceFill(['recurring_invoice_id' => $agreement->id])->save();

            // A followed article row that comes out at 0 kr (no price, or a 100 % discount) is not sent by itself: the draft waits for a person (audited).
            $held = $follows && $invoice->rows()->whereNotNull('article_id')->where('net_ore', 0)->exists();

            if ($follows) {
                $this->showLastRun($agreement, $agreementRows, $invoice);
            }

            $next = $agreement->dateAfter($date);
            $ended = $agreement->end_date !== null && $next->greaterThan($agreement->end_date);
            $agreement->forceFill([
                'next_invoice_date' => $next,
                'status' => $ended ? AgreementStatus::Ended : $agreement->status,
            ])->save();

            if ($ended) {
                Event::dispatch(new RecurringInvoiceStatusChanged($company->id, $agreement->id, $user->id, AgreementStatus::Ended->value));
            }

            if ($held) {
                Audit::record('agreement.held_as_draft', $agreement, ['number' => $agreement->number, 'invoice_id' => $invoice->id, 'reason' => 'zero_price', 'send_automatically' => $agreement->send_automatically], $company, $user);

                if ($agreement->send_automatically) {
                    $this->warn($user, $company, $agreement, $invoice);
                }
            }

            if ($agreement->send_automatically && ! $held) {
                $invoice = ($this->issue)($user, $invoice);
            }

            Audit::record('agreement.invoiced', $agreement, [
                'number' => $agreement->number,
                'invoice_id' => $invoice->id,
                'invoice_date' => $date->toDateString(),
                'next_invoice_date' => $next->toDateString(),
                'ended' => $ended,
            ], $company, $user);

            return $invoice;
        }));

        if ($agreement->send_automatically && ! $invoice->isDraft()) {
            CurrentCompany::run($agreement->company_id, function () use ($user, $agreement, $invoice): void {
                $customer = Customer::query()->findOrFail($agreement->customer_id);

                // A customer invoiced via Peppol gets the e-invoice instead of the e-mail (ADR-050).
                if ($customer->invoice_delivery === 'PEPPOL' && app(ModuleManager::class)->isEnabled(PeppolModule::KEY, $agreement->company_id)) {
                    app(SendInvoiceViaPeppol::class)($user, $invoice);

                    return;
                }

                ($this->send)($user, $invoice, (string) $customer->email);
            });
        }

        return $invoice;
    }

    /**
     * The held draft shows up under "Att göra" on the Översikt as a task for the
     * person who made the agreement (ADR-028), once per draft. Without the right
     * to make tasks the draft and the audit entry remain.
     */
    private function warn(User $user, Company $company, RecurringInvoice $agreement, Invoice $invoice): void
    {
        $exists = Task::query()->where('subject_type', 'invoice')->where('subject_id', (string) $invoice->id)->open()->exists();

        if ($exists) {
            return;
        }

        try {
            app(SaveTask::class)($user, $company, [
                'title' => __('Kontrollera fakturautkastet från avtal :number: en rad blev 0 kr', ['number' => $agreement->number]),
                'type' => TaskType::Invoices->value,
                'priority' => TaskPriority::High->value,
                'assignee_id' => $user->id,
                'subject_type' => 'invoice',
                'subject_id' => (string) $invoice->id,
            ]);
        } catch (AuthorizationException) {
            // The audit entry and the draft itself remain.
        }
    }

    /**
     * A row that follows the price lists (ADR-082 §6, decision 9): an article
     * row is priced on the run date, so it gives no price and no source of
     * its own; a free row keeps the price it was written with.
     *
     * @return array<string, mixed>
     */
    private static function followingRow(RecurringInvoiceRow $row): array
    {
        $input = $row->asInput();

        // A price typed on the agreement's row stays (it is marked MANUAL), as does a free row.
        if ($row->article_id === null || ! isset($input['article']) || $row->price_source === PriceSource::Manual) {
            return $input;
        }

        return [...array_diff_key($input, ['price_source' => true]), 'price' => ''];
    }

    /**
     * The agreement's own rows then only show the prices of the last run: the
     * rows the run priced from the price lists copy the invoice's prices, and
     * the agreement's totals follow.
     *
     * @param  Collection<int, RecurringInvoiceRow>  $rows
     */
    private function showLastRun(RecurringInvoice $agreement, Collection $rows, Invoice $invoice): void
    {
        $invoiceRows = $invoice->rows()->orderBy('position')->get()->values();

        foreach ($rows->values() as $index => $row) {
            $made = $invoiceRows->get($index);

            if ($row->article_id === null || $made === null || $row->price_source === PriceSource::Manual) {
                continue;
            }

            $row->forceFill([
                'unit_price_ore' => $made->unit_price_ore,
                'discount_bp' => $made->discount_bp,
                'net_ore' => $made->net_ore,
                'price_source' => $made->price_source,
                'price_explanation' => $made->price_explanation,
            ])->save();
        }

        $totals = InvoiceCalculator::totals($rows->map(fn (RecurringInvoiceRow $row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all());
        $agreement->forceFill(['net_ore' => $totals['net'], 'total_ore' => $totals['total']]);
    }

    /**
     * @param  ?string  $effectiveFrom  "Gäller från" (Y-m-d) for NONOX SaaS (ADR-080); passed on in the event only
     */
    public function setStatus(User $user, RecurringInvoice $agreement, AgreementStatus $status, ?string $effectiveFrom = null): RecurringInvoice
    {
        Gate::forUser($user)->authorize('update', $agreement);

        Validator::make(['effective_from' => $effectiveFrom], [
            'effective_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::today()->subYears(10)->toDateString(), 'before_or_equal:'.CarbonImmutable::today()->addYears(2)->toDateString()],
        ], [
            'effective_from.after_or_equal' => __('Gäller från kan vara högst tio år tillbaka.'),
            'effective_from.before_or_equal' => __('Gäller från kan vara högst två år framåt.'),
        ], ['effective_from' => __('gäller från')])->validate();

        return CurrentCompany::run($agreement->company_id, fn (): RecurringInvoice => DB::transaction(function () use ($user, $agreement, $status, $effectiveFrom): RecurringInvoice {
            $agreement->forceFill(['status' => $status])->save();

            Audit::record('agreement.status_changed', $agreement, ['number' => $agreement->number, 'status' => $status->value], $agreement->company_id, $user);

            Event::dispatch(new RecurringInvoiceStatusChanged($agreement->company_id, $agreement->id, $user->id, $status->value, $effectiveFrom));

            return $agreement;
        }));
    }
}
