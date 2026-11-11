<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\Article;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Modules\Crm\Models\QuoteTemplateRow;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * "Ny offert från mall" (ADR-046): a new open quote for a customer through
 * the invoicing core's SaveSalesDocument, dated today and valid for the
 * template's number of days. Article rows take the article's name, unit,
 * price and VAT rate of today (the template keeps no price for them); an
 * article archived since then is refused, so no quote is made at an old
 * price. The template's introduction and terms become the quote's message.
 */
final class CreateQuoteFromTemplate
{
    public function __construct(private readonly SaveSalesDocument $save) {}

    public function __invoke(User $user, Company $company, QuoteTemplate $template, int $customerId): SalesDocument
    {
        SaveQuoteTemplate::authorize($user, $company);

        if ($template->isArchived()) {
            throw ValidationException::withMessages(['template' => __('Mallen är arkiverad.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $template, $customerId): SalesDocument {
            $template->load('rows.article');
            $today = CarbonImmutable::now(LogActivity::ZONE);
            $rows = [];

            foreach ($template->rows as $row) {
                $rows[] = $this->row($row);
            }

            $notes = trim(implode("\n\n", array_filter([$template->intro_text, $template->terms_text])));

            $quote = ($this->save)($user, $company, SalesDocument::QUOTE, [
                'customer_id' => $customerId,
                'document_date' => $today->toDateString(),
                'until_date' => $today->addDays($template->validity_days)->toDateString(),
                'notes' => $notes === '' ? null : $notes,
                'rows' => $rows,
            ]);

            Audit::record('crm.quote_from_template', $quote, ['template_id' => $template->id, 'quote_id' => $quote->id], $company, $user);

            return $quote;
        });
    }

    /** @return array<string, mixed> */
    private function row(QuoteTemplateRow $row): array
    {
        $quantity = str_replace('.', ',', rtrim(rtrim($row->quantity, '0'), '.'));
        $discount = $row->discount_bp === 0 ? '' : sprintf('%d,%02d', intdiv($row->discount_bp, 100), $row->discount_bp % 100);

        if ($row->article_id === null) {
            return [
                'description' => (string) $row->description,
                'quantity' => $quantity,
                'unit_id' => (string) $row->unit_id,
                'unit' => (string) $row->unit,
                'price' => Money::ofOre($row->unit_price_ore)->format(withCurrency: false),
                'discount' => $discount,
                'vat_rate' => $row->vat_rate,
            ];
        }

        $article = $row->article;

        if (! $article instanceof Article || ! $article->is_active) {
            throw ValidationException::withMessages(['template' => __('En artikel i mallen finns inte längre eller är inte aktiv. Ändra mallen först.')]);
        }

        return [
            'article' => $article->number,
            'description' => $row->description ?? $article->name,
            'quantity' => $quantity,
            // Without a unit of its own the row takes the article's sales unit (SalesRowInput).
            'unit_id' => (string) $row->unit_id,
            'unit' => (string) $row->unit,
            'price' => $article->price_ore->format(withCurrency: false),
            'discount' => $discount,
            'vat_rate' => $article->vat_rate,
        ];
    }
}
