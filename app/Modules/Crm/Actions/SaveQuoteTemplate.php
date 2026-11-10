<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Units\UnitRegister;
use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Modules\Crm\Models\QuoteTemplateRow;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or changes a quote template (ADR-046), and archives or restores
 * one. Templates make quotes, so they need invoices:create, like quotes.
 *
 * Input: name, intro_text, terms_text (each at most 900 characters, so a
 * quote's message of 2 000 holds both), validity_days (1–365), and rows:
 * article (an active article's number) or a free row with description,
 * price (kronor) and vat_rate; quantity, unit_id (a unit of the company's
 * register, or unit: one of its codes) and discount (percent) on both. An
 * article row keeps no price: the quote takes the article's price on the
 * day it is made, and without a unit it takes the article's sales unit
 * then. A free row without a unit gets the company's default unit.
 */
final class SaveQuoteTemplate
{
    public const int MAX_TEXT = 900;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?QuoteTemplate $template = null): QuoteTemplate
    {
        self::authorize($user, $company);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'intro_text' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'terms_text' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'rows' => ['required', 'array', 'min:1', 'max:'.QuoteTemplate::MAX_ROWS],
            'rows.*.article' => ['nullable', 'string', 'max:50'],
            'rows.*.description' => ['nullable', 'string', 'max:255'],
            'rows.*.quantity' => ['nullable', 'string', 'max:20'],
            'rows.*.unit' => ['nullable', 'string', 'max:20'],
            'rows.*.unit_id' => ['nullable', 'integer'],
            'rows.*.price' => ['nullable', 'string', 'max:30'],
            'rows.*.vat_rate' => ['nullable', 'integer', Rule::in(InvoiceCalculator::VAT_RATES)],
            'rows.*.discount' => ['nullable', 'string', 'max:10'],
        ], [
            'rows.required' => __('Lägg till minst en rad.'),
            'rows.min' => __('Lägg till minst en rad.'),
        ], ['name' => __('namn'), 'validity_days' => __('giltighet'), 'intro_text' => __('inledning'), 'terms_text' => __('villkor')])->validate();

        return DB::transaction(fn (): QuoteTemplate => CurrentCompany::run($company, function () use ($user, $company, $validated, $template): QuoteTemplate {
            $rows = $this->rows($validated['rows'], $template === null ? [] : array_values($template->rows()->whereNotNull('unit_id')->pluck('unit_id')->map(intval(...))->all()));
            $created = $template === null;
            $template ??= new QuoteTemplate(['created_by' => $user->id]);

            $template->fill([
                'name' => trim((string) $validated['name']),
                'intro_text' => blank($validated['intro_text'] ?? null) ? null : (string) $validated['intro_text'],
                'terms_text' => blank($validated['terms_text'] ?? null) ? null : (string) $validated['terms_text'],
                'validity_days' => (int) $validated['validity_days'],
            ])->save();

            $template->rows()->delete();

            foreach ($rows as $position => $row) {
                QuoteTemplateRow::create([...$row, 'template_id' => $template->id, 'position' => $position]);
            }

            Audit::record($created ? 'crm.quote_template_created' : 'crm.quote_template_updated', $template, ['template_id' => $template->id, 'rows' => count($rows)], $company, $user);

            return $template;
        }));
    }

    public function archive(User $user, Company $company, QuoteTemplate $template, bool $archived = true): QuoteTemplate
    {
        self::authorize($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $template, $archived): void {
            $template->forceFill(['archived_at' => $archived ? now() : null])->save();
            Audit::record($archived ? 'crm.quote_template_archived' : 'crm.quote_template_restored', $template, ['template_id' => $template->id], $company, $user);
        });

        return $template;
    }

    public static function authorize(User $user, Company $company): void
    {
        abort_unless(app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company), 404);
        abort_unless($user->hasTeamPermission($company, 'invoices:create'), 403);
    }

    /**
     * @param  array<int, array<string, mixed>>  $input
     * @param  list<int>  $keptUnitIds  the units the template's rows had: still allowed when archived
     * @return list<array<string, mixed>>
     */
    private function rows(array $input, array $keptUnitIds): array
    {
        $units = UnitRegister::load();
        $numbers = array_filter(array_map(fn (array $row): string => trim((string) ($row['article'] ?? '')), $input));
        $articles = Article::query()->whereIn('number', $numbers)->where('is_active', true)->get()->keyBy('number');
        $rows = [];
        $errors = [];

        foreach (array_values($input) as $index => $row) {
            $line = $index + 1;
            $number = trim((string) ($row['article'] ?? ''));
            $article = $number === '' ? null : $articles->get($number);
            $quantity = InvoiceCalculator::quantity((string) (($row['quantity'] ?? '') === '' ? '1' : $row['quantity']));
            $discount = InvoiceCalculator::discount((string) ($row['discount'] ?? ''));

            if ($number !== '' && $article === null) {
                $errors["rows.{$index}.article"] = __('Rad :line: artikeln finns inte eller är inte aktiv.', ['line' => $line]);

                continue;
            }

            if ($quantity === null || str_starts_with($quantity, '-') || preg_match('/^0+(\.0+)?$/', $quantity) === 1) {
                $errors["rows.{$index}.quantity"] = __('Rad :line: skriv ett antal, till exempel 2 eller 1,5.', ['line' => $line]);

                continue;
            }

            if ($discount === null) {
                $errors["rows.{$index}.discount"] = __('Rad :line: rabatten ska vara mellan 0 och 100 procent.', ['line' => $line]);

                continue;
            }

            $unit = null;

            try {
                // An article row without a unit follows the article's sales unit when the quote is made.
                $unit = $article instanceof Article && blank($row['unit_id'] ?? null) && blank($row['unit'] ?? null)
                    ? null
                    : $units->forRow($row['unit_id'] ?? null, $row['unit'] ?? null, null, "rows.{$index}.unit_id");
                $units->assertSelectable(["rows.{$index}.unit_id" => $unit?->id], $keptUnitIds);
            } catch (ValidationException $exception) {
                $errors["rows.{$index}.unit_id"] = __('Rad :line: :message', ['line' => $line, 'message' => $exception->validator->errors()->first()]);

                continue;
            }

            if ($article instanceof Article) {
                $rows[] = [
                    'article_id' => $article->id,
                    'description' => blank($row['description'] ?? null) ? null : trim((string) $row['description']),
                    'quantity' => $quantity,
                    'unit' => $unit?->code,
                    'unit_id' => $unit?->id,
                    'unit_price_ore' => 0,
                    'vat_rate' => $article->vat_rate,
                    'discount_bp' => $discount,
                ];

                continue;
            }

            if (blank($row['description'] ?? null)) {
                $errors["rows.{$index}.description"] = __('Rad :line: välj en artikel eller skriv en benämning.', ['line' => $line]);

                continue;
            }

            try {
                $price = Money::parse((string) (($row['price'] ?? '') === '' ? '0' : $row['price']));
            } catch (InvalidArgumentException) {
                $errors["rows.{$index}.price"] = __('Rad :line: priset går inte att läsa. Skriv till exempel 149,00.', ['line' => $line]);

                continue;
            }

            $rows[] = [
                'article_id' => null,
                'description' => trim((string) $row['description']),
                'quantity' => $quantity,
                'unit' => $unit?->code,
                'unit_id' => $unit?->id,
                'unit_price_ore' => $price->ore,
                'vat_rate' => (int) ($row['vat_rate'] ?? 2500),
                'discount_bp' => $discount,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }
}
