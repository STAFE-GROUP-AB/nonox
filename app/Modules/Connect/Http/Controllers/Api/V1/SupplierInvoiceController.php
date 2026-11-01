<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\DeleteSupplierInvoiceDraft;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Models\SupplierInvoice;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ApiInput;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Supplier invoices over the API (ADR-032), through the same Actions as the
 * UI: a draft is saved with SaveSupplierInvoice (the invoice file can be
 * added as multipart), registered with RegisterSupplierInvoice (number,
 * booked in series D under fakturametoden), approved with
 * ApproveSupplierInvoice and paid with PaySupplierInvoice.
 *
 * Segregation of duties (AGENTS.md §4) is the Action's: the person who
 * registered an invoice cannot approve it while the company requires
 * separate approval, whichever app or key they use. Amounts are whole öre;
 * a credit note is sent with credit: true and positive amounts.
 */
final class SupplierInvoiceController
{
    use ActsAsCredential;

    private const array RELATIONS = ['rows.account', 'rows.costCenter', 'rows.project', 'payments.account', 'supplier'];

    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'status' => ['nullable', 'in:DRAFT,REGISTERED,APPROVED,PAID'],
            'supplier_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();

        $query = SupplierInvoice::query()->with(self::RELATIONS)
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['supplier_id'] ?? null), fn ($query) => $query->where('supplier_id', (int) $filters['supplier_id']))
            ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->where('invoice_date', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->where('invoice_date', '<=', $filters['date_to']));

        return CursorList::respond($request, $query, fn (SupplierInvoice $invoice): array => ApiPresenter::supplierInvoice($invoice));
    }

    public function show(SupplierInvoice $supplierInvoice): JsonResponse
    {
        Gate::forUser($this->user())->authorize('view', $supplierInvoice);

        return CursorList::one(ApiPresenter::supplierInvoice($supplierInvoice->load(self::RELATIONS)));
    }

    public function store(Request $request, SaveSupplierInvoice $save): JsonResponse
    {
        $invoice = $save($this->user(), $this->company(), $this->input(ApiInput::body($request->json()->all())));

        return CursorList::one(ApiPresenter::supplierInvoice($invoice->refresh()->load(self::RELATIONS)), 201)
            ->header('Location', route('connect.api.supplier-invoices.show', $invoice));
    }

    public function update(Request $request, SupplierInvoice $supplierInvoice, SaveSupplierInvoice $save): JsonResponse
    {
        Gate::forUser($this->user())->authorize('update', $supplierInvoice);

        $saved = $save($this->user(), $this->company(), $this->input([...$this->current($supplierInvoice), ...ApiInput::body($request->json()->all())]), $supplierInvoice);

        return CursorList::one(ApiPresenter::supplierInvoice($saved->refresh()->load(self::RELATIONS)));
    }

    /** Adds or replaces the invoice itself (PDF, JPG, PNG or WebP) on a draft. */
    public function file(Request $request, SupplierInvoice $supplierInvoice, SaveSupplierInvoice $save): JsonResponse
    {
        Gate::forUser($this->user())->authorize('update', $supplierInvoice);

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('Skicka fakturan som fältet file (multipart/form-data).')]);
        }

        $saved = $save($this->user(), $this->company(), $this->input($this->current($supplierInvoice)), $supplierInvoice, $file);

        return CursorList::one(ApiPresenter::supplierInvoice($saved->refresh()->load(self::RELATIONS)));
    }

    public function destroy(SupplierInvoice $supplierInvoice, DeleteSupplierInvoiceDraft $delete): Response
    {
        $delete($this->user(), $supplierInvoice);

        return response()->noContent();
    }

    public function register(SupplierInvoice $supplierInvoice, RegisterSupplierInvoice $register): JsonResponse
    {
        $registered = $register($this->user(), $supplierInvoice);

        return CursorList::one(ApiPresenter::supplierInvoice($registered->refresh()->load(self::RELATIONS)));
    }

    public function approve(SupplierInvoice $supplierInvoice, ApproveSupplierInvoice $approve): JsonResponse
    {
        $approved = $approve($this->user(), $supplierInvoice);

        return CursorList::one(ApiPresenter::supplierInvoice($approved->refresh()->load(self::RELATIONS)));
    }

    public function pay(Request $request, SupplierInvoice $supplierInvoice, PaySupplierInvoice $pay): JsonResponse
    {
        $input = Validator::make(ApiInput::body($request->json()->all()), [
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount_ore' => ['required', 'integer', 'not_in:0'],
            'foreign_amount_minor' => ['nullable', 'integer', 'not_in:0'],
            'account' => ['nullable', 'integer', 'between:1900,1999'],
        ], [], ['paid_on' => __('betaldag'), 'amount_ore' => __('belopp')])->validate();

        $payment = $pay(
            $this->user(),
            $supplierInvoice,
            CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['paid_on']) ?: CarbonImmutable::today(),
            Money::ofOre((int) $input['amount_ore']),
            (int) ($input['account'] ?? SupplierInvoiceBooking::BANK),
            isset($input['foreign_amount_minor']) ? (int) $input['foreign_amount_minor'] : null,
        );

        return CursorList::one(ApiPresenter::supplierPayment($payment->load('account')), 201);
    }

    /**
     * The draft as API input, to merge a partial change into.
     *
     * @return array<string, mixed>
     */
    private function current(SupplierInvoice $invoice): array
    {
        $current = ApiPresenter::supplierInvoice($invoice->load(self::RELATIONS));
        $sign = $invoice->isCredit() ? -1 : 1;

        return [
            ...array_intersect_key($current, array_flip(['supplier_id', 'invoice_number', 'ocr', 'invoice_date', 'due_date', 'description', 'vat_treatment', 'foreign_total_minor', 'exchange_rate_micros'])),
            'credit' => $invoice->isCredit(),
            'currency' => $invoice->invoice_currency,
            'total_ore' => $sign * (int) $current['total_ore'],
            'vat_ore' => $sign * (int) $current['vat_ore'],
            'foreign_total_minor' => $current['foreign_total_minor'] === null ? null : $sign * (int) $current['foreign_total_minor'],
            'rows' => array_map(fn (array $row): array => [...$row, 'amount_ore' => $sign * (int) $row['amount_ore']], $current['rows']),
        ];
    }

    /**
     * The API's body as SaveSupplierInvoice reads it: öre and hundredths to
     * written amounts, millionths to a written rate.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function input(array $body): array
    {
        $input = array_intersect_key($body, array_flip(['supplier_id', 'invoice_number', 'ocr', 'invoice_date', 'due_date', 'description', 'credit', 'vat_treatment', 'currency']));
        $input['total'] = ApiInput::kronor($body['total_ore'] ?? null, 'total_ore');
        $input['vat'] = ApiInput::kronor($body['vat_ore'] ?? 0, 'vat_ore');

        if (($body['foreign_total_minor'] ?? null) !== null) {
            $input['foreign_total'] = ApiInput::kronor($body['foreign_total_minor'], 'foreign_total_minor');
        }

        if (($body['exchange_rate_micros'] ?? null) !== null) {
            $micros = $body['exchange_rate_micros'];

            if (! is_int($micros) || $micros <= 0) {
                throw ValidationException::withMessages(['exchange_rate_micros' => __('Skriv kursen i miljondelar som ett heltal, till exempel 9826400 för 9,8264.')]);
            }

            $input['exchange_rate'] = sprintf('%d.%06d', intdiv($micros, 1_000_000), $micros % 1_000_000);
        }

        $rows = $body['rows'] ?? [];
        $input['rows'] = is_array($rows) ? array_map(function (mixed $row, int|string $index): array {
            $row = is_array($row) ? $row : [];

            return [
                'account' => isset($row['account']) ? (string) $row['account'] : '',
                'description' => $row['description'] ?? null,
                'amount' => ApiInput::kronor($row['amount_ore'] ?? null, "rows.{$index}.amount_ore"),
                'vat_rate' => $row['vat_rate'] ?? null,
                'cost_center' => $row['cost_center'] ?? null,
                'project' => $row['project'] ?? null,
            ];
        }, array_values($rows), array_keys(array_values($rows))) : [];

        return $input;
    }
}
