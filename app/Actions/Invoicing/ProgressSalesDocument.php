<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\SalesDocumentStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Models\User;
use App\Modules\Crm\Models\Deal;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Moves a quote or an order along: a quote becomes an order, a quote or an
 * order becomes a draft invoice, or it is cancelled. The rows follow along
 * unchanged, so nothing has to be typed twice.
 */
class ProgressSalesDocument
{
    public function __construct(
        private readonly SaveSalesDocument $saveDocument,
        private readonly SaveDraftInvoice $saveInvoice,
    ) {}

    public function toOrder(User $user, SalesDocument $quote): SalesDocument
    {
        Gate::forUser($user)->authorize('update', $quote);

        if (! $quote->isQuote()) {
            throw ValidationException::withMessages(['document' => __('Bara en offert kan bli en order.')]);
        }

        return $this->run($quote, function (Company $company) use ($user, $quote): SalesDocument {
            $order = ($this->saveDocument)($user, $company, SalesDocument::ORDER, [
                ...$this->input($quote),
                'document_date' => CarbonImmutable::today()->toDateString(),
                'until_date' => null,
            ]);

            $order->forceFill(['from_quote_id' => $quote->id])->save();
            $quote->forceFill(['status' => SalesDocumentStatus::Accepted])->save();
            Deal::query()->where('quote_id', $quote->id)->update(['order_id' => $order->id]);

            Audit::record('quote.accepted', $quote, ['number' => $quote->number, 'order' => $order->number], $company, $user);

            return $order;
        });
    }

    public function toInvoice(User $user, SalesDocument $document): Invoice
    {
        Gate::forUser($user)->authorize('update', $document);
        Gate::forUser($user)->authorize('create', [Invoice::class, Company::query()->findOrFail($document->company_id)]);

        return $this->run($document, function (Company $company) use ($user, $document): Invoice {
            $input = $this->input($document);

            $invoice = ($this->saveInvoice)($user, $company, [
                ...$input,
                'invoice_date' => CarbonImmutable::today()->toDateString(),
                'delivery_date' => $document->isQuote() ? null : $document->until_date?->toDateString(),
            ]);

            $document->forceFill(['status' => SalesDocumentStatus::Invoiced, 'invoice_id' => $invoice->id])->save();
            // A deal made from this quote or order follows along, so winning it needs no manual pick.
            Deal::query()->where(fn ($query) => $query->where('quote_id', $document->id)->orWhere('order_id', $document->id))->update(['invoice_id' => $invoice->id]);

            Audit::record($document->isQuote() ? 'quote.invoiced' : 'order.invoiced', $document, ['number' => $document->number], $company, $user);

            return $invoice;
        });
    }

    public function cancel(User $user, SalesDocument $document): void
    {
        Gate::forUser($user)->authorize('update', $document);

        $this->run($document, function (Company $company) use ($user, $document): SalesDocument {
            $document->forceFill(['status' => SalesDocumentStatus::Cancelled])->save();

            Audit::record($document->isQuote() ? 'quote.cancelled' : 'order.cancelled', $document, ['number' => $document->number], $company, $user);

            return $document;
        });
    }

    /**
     * @template T
     *
     * @param  callable(Company): T  $callback
     * @return T
     */
    private function run(SalesDocument $document, callable $callback): mixed
    {
        return CurrentCompany::run($document->company_id, fn () => DB::transaction(function () use ($document, $callback) {
            $locked = SalesDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['document' => __(':title är inte längre öppen.', ['title' => $locked->title()])]);
            }

            return $callback(Company::query()->findOrFail($document->company_id));
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function input(SalesDocument $document): array
    {
        $document->load('rows.account');

        return [
            'customer_id' => $document->customer_id,
            'your_reference' => $document->your_reference,
            'our_reference' => $document->our_reference,
            'notes' => $document->notes,
            'rows' => $document->rows->map(fn (SalesDocumentRow $row): array => $row->asInput())->all(),
        ];
    }
}
