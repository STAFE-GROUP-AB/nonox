<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SendInvoice;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoicePdf;
use App\Models\Article;
use App\Models\Invoice;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ApiInput;
use App\Modules\Connect\Support\Problem;
use App\Support\Currency\ExchangeRate;
use App\Support\Money\Money;
use App\Support\WorkQuota;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Customer invoices over the API (ADR-032), through the same Actions as the
 * UI: a draft is created and changed with SaveDraftInvoice, issued (number,
 * OCR, booked in series B under fakturametoden) with IssueInvoice, e-mailed
 * with SendInvoice, credited with CreditInvoice and paid with
 * RegisterPayment. An issued invoice never changes.
 *
 * Amounts are whole öre; row prices exclude VAT; VAT rates and discounts
 * are basis points (2500 is 25 %, 1050 is 10.5 %).
 *
 * An invoice in another currency (invoice_currency, ADR-025) takes its row
 * prices as unit_price_minor, hundredths of that currency, and is booked in
 * kronor at exchange_rate_micros (default: Riksbanken's rate on the invoice
 * date). Its payments give the foreign amount paid (foreign_amount_minor,
 * default what remains) and amount_ore, the kronor that reached the bank.
 */
final class InvoiceController
{
    use ActsAsCredential;

    private const array RELATIONS = ['rows.account', 'rows.article', 'payments.account', 'customer', 'peppolTransmissions'];

    private const array FIELDS = ['customer_id', 'invoice_date', 'due_date', 'delivery_date', 'your_reference', 'our_reference', 'notes', 'reverse_charge', 'foreign_sale', 'tax_reduction', 'property_designation', 'invoice_currency'];

    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'status' => ['nullable', 'in:DRAFT,ISSUED,PAID,CREDITED'],
            'kind' => ['nullable', 'in:INVOICE,CREDIT'],
            'customer_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();

        $query = Invoice::query()->with(self::RELATIONS)
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['kind'] ?? null), fn ($query) => $query->where('kind', $filters['kind']))
            ->when(filled($filters['customer_id'] ?? null), fn ($query) => $query->where('customer_id', (int) $filters['customer_id']))
            ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->where('invoice_date', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->where('invoice_date', '<=', $filters['date_to']));

        return CursorList::respond($request, $query, fn (Invoice $invoice): array => ApiPresenter::invoice($invoice));
    }

    public function show(Invoice $invoice): JsonResponse
    {
        Gate::forUser($this->user())->authorize('view', $invoice);

        return CursorList::one(ApiPresenter::invoice($invoice->load(self::RELATIONS)));
    }

    public function store(Request $request, SaveDraftInvoice $save): JsonResponse
    {
        $invoice = $save($this->user(), $this->company(), $this->input(ApiInput::body($request->json()->all())));

        return CursorList::one(ApiPresenter::invoice($invoice->refresh()->load(self::RELATIONS)), 201)
            ->header('Location', route('connect.api.invoices.show', $invoice));
    }

    public function update(Request $request, Invoice $invoice, SaveDraftInvoice $save): JsonResponse
    {
        Gate::forUser($this->user())->authorize('update', $invoice);

        $current = ApiPresenter::invoice($invoice->load(self::RELATIONS));
        $body = [
            ...array_intersect_key($current, array_flip(self::FIELDS)),
            'rows' => $current['rows'],
            ...ApiInput::body($request->json()->all()),
        ];

        $saved = $save($this->user(), $this->company(), $this->input($body), $invoice);

        return CursorList::one(ApiPresenter::invoice($saved->refresh()->load(self::RELATIONS)));
    }

    public function issue(Invoice $invoice, IssueInvoice $issue): JsonResponse
    {
        $issued = $issue($this->user(), $invoice);

        return CursorList::one(ApiPresenter::invoice($issued->refresh()->load(self::RELATIONS)));
    }

    public function send(Request $request, Invoice $invoice, SendInvoice $send): JsonResponse
    {
        $email = $request->json('email') ?? $invoice->customer_snapshot['email'] ?? '';
        $send($this->user(), $invoice, is_string($email) ? $email : '');

        return CursorList::one(ApiPresenter::invoice($invoice->refresh()->load(self::RELATIONS)));
    }

    public function credit(Invoice $invoice, CreditInvoice $credit): JsonResponse
    {
        $draft = $credit($this->user(), $invoice);

        return CursorList::one(ApiPresenter::invoice($draft->refresh()->load(self::RELATIONS)), 201)
            ->header('Location', route('connect.api.invoices.show', $draft));
    }

    public function registerPayment(Request $request, Invoice $invoice, RegisterPayment $register): JsonResponse
    {
        $input = Validator::make(ApiInput::body($request->json()->all()), [
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount_ore' => ['required', 'integer', 'min:1'],
            'account' => ['nullable', 'integer', 'between:1900,1999'],
            'foreign_amount_minor' => ['nullable', 'integer', 'min:1'],
        ], [], ['paid_on' => __('betaldag'), 'amount_ore' => __('belopp'), 'foreign_amount_minor' => __('belopp i valuta')])->validate();

        $payment = $register(
            $this->user(),
            $invoice,
            CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['paid_on']) ?: CarbonImmutable::today(),
            Money::ofOre((int) $input['amount_ore']),
            (int) ($input['account'] ?? InvoiceBooking::BANK),
            isset($input['foreign_amount_minor']) ? (int) $input['foreign_amount_minor'] : null,
        );

        return CursorList::one(ApiPresenter::invoicePayment($payment->load('account')), 201);
    }

    public function pdf(Invoice $invoice): Response|JsonResponse
    {
        Gate::forUser($this->user())->authorize('view', $invoice);

        if (! WorkQuota::attempt($this->company(), 'pdf')) {
            return Problem::make(429, 'rate_limited', WorkQuota::message(), [], ['Retry-After' => '60']);
        }

        return new Response(InvoicePdf::render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.InvoicePdf::filename($invoice).'"',
        ]);
    }

    /**
     * The API's body as SaveDraftInvoice reads it: öre to kronor, basis
     * points to percent, and an article's price, text and VAT rate when a
     * row names an article and leaves them out.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function input(array $body): array
    {
        $input = array_intersect_key($body, array_flip(self::FIELDS));
        $currency = strtoupper(is_string($body['invoice_currency'] ?? null) ? $body['invoice_currency'] : 'SEK');
        $foreign = $currency !== 'SEK';
        unset($input['invoice_currency']);
        $input['currency'] = $currency;

        if (isset($body['exchange_rate_micros'])) {
            $micros = $body['exchange_rate_micros'];

            if (! is_int($micros) || $micros <= 0) {
                throw ValidationException::withMessages(['exchange_rate_micros' => __('Skriv kursen i miljondelar av en krona per enhet, till exempel 11234500 för 11,2345.')]);
            }

            $input['exchange_rate'] = intdiv($micros, ExchangeRate::SCALE).','.str_pad((string) ($micros % ExchangeRate::SCALE), 6, '0', STR_PAD_LEFT);
        }

        $rows = is_array($body['rows'] ?? null) ? array_values($body['rows']) : ($body['rows'] ?? null);

        if (! is_array($rows)) {
            return [...$input, 'rows' => $rows];
        }

        $numbers = array_filter(array_map(fn (mixed $row): string => is_array($row) ? trim((string) ($row['article'] ?? '')) : '', $rows));
        $articles = Article::query()->whereIn('number', $numbers)->get()->keyBy('number');

        $input['rows'] = array_map(function (mixed $row, int $index) use ($articles, $foreign): array {
            $row = is_array($row) ? $row : [];
            $article = $articles->get(trim((string) ($row['article'] ?? '')));

            return [
                'article' => $row['article'] ?? null,
                'description' => $row['description'] ?? $article?->name,
                'quantity' => ApiInput::quantity($row['quantity'] ?? 1, "rows.{$index}.quantity"),
                'unit' => $row['unit'] ?? $article?->unit,
                // Article prices are kronor, so an invoice in another currency needs its own price.
                'price' => $foreign
                    ? ApiInput::kronor($row['unit_price_minor'] ?? 0, "rows.{$index}.unit_price_minor")
                    : ApiInput::kronor($row['unit_price_ore'] ?? $row['unit_price_minor'] ?? $article?->price_ore->ore ?? 0, "rows.{$index}.unit_price_ore"),
                'discount' => ApiInput::percent($row['discount_bp'] ?? 0, "rows.{$index}.discount_bp"),
                'vat_rate' => $row['vat_rate'] ?? ($article === null ? 2500 : $article->vat_rate),
                'account' => $row['account'] ?? null,
                'labour' => (bool) ($row['labour'] ?? false),
            ];
        }, $rows, array_keys($rows));

        return $input;
    }
}
