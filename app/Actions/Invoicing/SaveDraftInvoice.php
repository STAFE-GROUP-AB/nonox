<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or updates a draft invoice and recalculates its amounts. Nothing
 * is booked until the invoice is issued.
 */
class SaveDraftInvoice
{
    /**
     * @param  array<string, mixed>  $input  customer_id, invoice_date, due_date (optional), delivery_date,
     *                                       your_reference, our_reference, notes,
     *                                       rows (list of article (number, optional), description,
     *                                       quantity, unit, price (kronor), discount (percent),
     *                                       vat_rate, account (number, optional))
     */
    public function __invoke(User $user, Company $company, array $input, ?Invoice $draft = null): Invoice
    {
        $draft === null
            ? Gate::forUser($user)->authorize('create', [Invoice::class, $company])
            : Gate::forUser($user)->authorize('update', $draft);

        $validated = Validator::make($input, [
            'customer_id' => ['required', 'integer'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'your_reference' => ['nullable', 'string', 'max:255'],
            'our_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.vat_rate' => ['required', 'integer', Rule::in(InvoiceCalculator::VAT_RATES)],
        ], [
            'customer_id.required' => __('Välj en kund.'),
            'rows.required' => __('Lägg till minst en rad.'),
            'rows.min' => __('Lägg till minst en rad.'),
            'rows.*.description.required' => __('Varje rad behöver en benämning.'),
            'due_date.after_or_equal' => __('Förfallodagen kan inte vara före fakturadatum.'),
        ], [
            'invoice_date' => __('fakturadatum'),
            'due_date' => __('förfallodag'),
        ])->validate();

        return DB::transaction(fn (): Invoice => CurrentCompany::run($company, function () use ($validated, $input, $user, $draft): Invoice {
            $customer = Customer::query()->find((int) $validated['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Välj en kund.')]);

            $invoiceDate = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['invoice_date']) ?: CarbonImmutable::today();
            $dueDate = isset($validated['due_date'])
                ? (CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['due_date']) ?: $invoiceDate)
                : $invoiceDate->addDays($customer->payment_terms_days);

            // validate() keeps only fields with rules, so the rows are read from the input itself.
            /** @var list<array<string, mixed>> $rowInput */
            $rowInput = array_values(array_filter((array) $input['rows'], is_array(...)));
            $rows = $this->rows($rowInput, $draft?->isCredit() ?? false);
            $totals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => $row['net_ore'], 'vat_rate' => $row['vat_rate']], $rows));

            $invoice = $draft ?? new Invoice(['created_by' => $user->id]);
            $invoice->fill([
                'customer_id' => $customer->id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'delivery_date' => $validated['delivery_date'] ?? null,
                'your_reference' => $validated['your_reference'] ?? null,
                'our_reference' => $validated['our_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
            $invoice->forceFill([
                'net_ore' => $totals['net'],
                'vat_ore' => $totals['vat'],
                'rounding_ore' => $totals['rounding'],
                'total_ore' => $totals['total'],
            ])->save();

            InvoiceRow::query()->where('invoice_id', $invoice->id)->delete();

            foreach ($rows as $position => $row) {
                InvoiceRow::create(['invoice_id' => $invoice->id, 'position' => $position, ...$row]);
            }

            return $invoice->load('rows');
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $input
     * @return list<array{article_id: ?int, description: string, quantity: string, unit: string, unit_price_ore: int, discount_bp: int, vat_rate: int, account_id: int, net_ore: int}>
     */
    private function rows(array $input, bool $credit): array
    {
        $articles = Article::query()->whereIn('number', array_filter(array_map(fn (array $row): string => trim((string) ($row['article'] ?? '')), $input)))->get()->keyBy('number');
        $accountNumbers = array_filter(array_map(fn (array $row): int => (int) ($row['account'] ?? 0), $input));
        $accounts = Account::query()->whereIn('number', [...$accountNumbers, ...array_values(SaveArticle::DEFAULT_ACCOUNTS)])->get()->keyBy('number');
        $accountsById = $accounts->keyBy('id');
        $errors = [];
        $rows = [];

        foreach ($input as $index => $row) {
            $line = $index + 1;
            $article = $articles->get(trim((string) ($row['article'] ?? '')));
            $quantity = InvoiceCalculator::quantity((string) ($row['quantity'] ?? ''));
            $rate = (int) $row['vat_rate'];

            if ($quantity === null || preg_match('/^-?0+(\.0+)?$/', $quantity) === 1) {
                $errors["rows.{$index}.quantity"] = __('Rad :line: skriv ett antal, till exempel 2 eller 1,5.', ['line' => $line]);

                continue;
            }

            if ($credit && ! str_starts_with($quantity, '-')) {
                $quantity = "-{$quantity}";
            }

            try {
                $price = Money::parse((string) ($row['price'] ?? '0'));
            } catch (InvalidArgumentException) {
                $errors["rows.{$index}.price"] = __('Rad :line: priset går inte att läsa. Skriv till exempel 149,00.', ['line' => $line]);

                continue;
            }

            $discountBp = InvoiceCalculator::discount((string) ($row['discount'] ?? ''));

            if ($discountBp === null) {
                $errors["rows.{$index}.discount"] = __('Rad :line: rabatten ska vara mellan 0 och 100 procent.', ['line' => $line]);

                continue;
            }

            $accountNumber = (int) ($row['account'] ?? 0);
            $account = $accountNumber > 0
                ? $accounts->get($accountNumber)
                : ($article !== null ? $accountsById->get($article->account_id) : $accounts->get(SaveArticle::DEFAULT_ACCOUNTS[$rate]));

            if (! $account instanceof Account) {
                $errors["rows.{$index}.account"] = __('Rad :line: intäktskontot finns inte i kontoplanen.', ['line' => $line]);

                continue;
            }

            $rows[] = [
                'article_id' => $article?->id,
                'description' => trim((string) $row['description']),
                'quantity' => $quantity,
                'unit' => mb_substr(trim((string) ($row['unit'] ?? '')) ?: ($article->unit ?? 'st'), 0, 10),
                'unit_price_ore' => $price->ore,
                'discount_bp' => $discountBp,
                'vat_rate' => $rate,
                'account_id' => $account->id,
                'net_ore' => InvoiceCalculator::rowNet($price, $quantity, $discountBp)->ore,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }
}
