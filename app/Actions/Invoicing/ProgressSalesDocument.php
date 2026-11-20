<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\SalesDocumentStatus;
use App\Domain\Pricing\PriceFloorGuard;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\RowPriceSnapshot;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Models\User;
use App\Modules\Crm\Domain\DealStatus;
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
 * unchanged, so nothing has to be typed twice: the customer accepted the
 * quoted prices, so every row keeps its price, source and explanation even
 * where a price list has changed since (ADR-082 §6), and the currency
 * follows (decision 13). With $todaysPrices (offered for a quote whose last
 * day has passed: "Hämta dagens priser") every row not priced by hand gets
 * today's price instead; "Uppdatera priser" in the new document is the
 * other way to them.
 */
class ProgressSalesDocument
{
    public function __construct(
        private readonly SaveSalesDocument $saveDocument,
        private readonly SaveDraftInvoice $saveInvoice,
    ) {}

    public function toOrder(User $user, SalesDocument $quote, bool $todaysPrices = false): SalesDocument
    {
        Gate::forUser($user)->authorize('update', $quote);

        if (! $quote->isQuote()) {
            throw ValidationException::withMessages(['document' => __('Bara en offert kan bli en order.')]);
        }

        return $this->run($quote, function (Company $company) use ($user, $quote, $todaysPrices): SalesDocument {
            // A quote whose rows changed after they were approved is not accepted as it stands (ADR-082 §9, P7).
            PriceFloorGuard::ensureCleared($company, $quote);

            $order = ($this->saveDocument)($user, $company, SalesDocument::ORDER, [
                ...$this->input($quote, $todaysPrices),
                'document_date' => CarbonImmutable::today()->toDateString(),
                'until_date' => null,
            ], carried: $todaysPrices ? [] : RowPriceSnapshot::of($quote->rows, $quote->currency));

            $order->forceFill(['from_quote_id' => $quote->id])->save();
            $quote->forceFill(['status' => SalesDocumentStatus::Accepted])->save();
            // An open deal made from this quote follows along; a won or lost deal and a link already chosen stay.
            Deal::query()->where('status', DealStatus::Open)->where('quote_id', $quote->id)->whereNull('order_id')->update(['order_id' => $order->id]);

            Audit::record('quote.accepted', $quote, ['number' => $quote->number, 'order' => $order->number], $company, $user);

            return $order;
        });
    }

    public function toInvoice(User $user, SalesDocument $document, bool $todaysPrices = false): Invoice
    {
        Gate::forUser($user)->authorize('update', $document);
        Gate::forUser($user)->authorize('create', [Invoice::class, Company::query()->findOrFail($document->company_id)]);

        return $this->run($document, function (Company $company) use ($user, $document, $todaysPrices): Invoice {
            $input = $this->input($document, $todaysPrices);

            $invoice = ($this->saveInvoice)($user, $company, [
                ...$input,
                'invoice_date' => CarbonImmutable::today()->toDateString(),
                'delivery_date' => $document->isQuote() ? null : $document->until_date?->toDateString(),
            ], carried: $todaysPrices ? [] : RowPriceSnapshot::of($document->rows, $document->currency));

            $document->forceFill(['status' => SalesDocumentStatus::Invoiced, 'invoice_id' => $invoice->id])->save();
            // A deal made from this quote or order follows along, so winning it needs no manual pick.
            Deal::query()->where('status', DealStatus::Open)->whereNull('invoice_id')->where(fn ($query) => $query->where('quote_id', $document->id)->orWhere('order_id', $document->id))->update(['invoice_id' => $invoice->id]);

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
     * The document as input for the next one; with $todaysPrices a row not
     * priced by hand is sent without its price, so it gets today's.
     *
     * @return array<string, mixed>
     */
    private function input(SalesDocument $document, bool $todaysPrices = false): array
    {
        $document->load(['rows.account', 'rows.costCenter', 'rows.project', 'rows.article:id,number']);

        return [
            'customer_id' => $document->customer_id,
            'currency' => $document->currency,
            'your_reference' => $document->your_reference,
            'our_reference' => $document->our_reference,
            'notes' => $document->notes,
            'rows' => $document->rows->map(function (SalesDocumentRow $row) use ($todaysPrices): array {
                $input = $row->asInput();

                if ($todaysPrices && isset($input['article']) && ($input['price_source'] ?? '') !== PriceSource::Manual->value) {
                    return [...$input, 'price' => '', 'price_source' => ''];
                }

                return $input;
            })->all(),
        ];
    }
}
