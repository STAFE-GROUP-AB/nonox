<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\SaveQuoteTemplate;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Modules\Crm\Models\QuoteTemplateRow;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Offertmallar (/kunder/offertmallar, ADR-046): reusable quotes with rows
 * from the article register (priced on the day a quote is made) or free
 * rows, an introduction, terms and a validity. invoices:create.
 */
class QuoteTemplatesPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'arkiverade', except: false)]
    public bool $archived = false;

    /** null: no editor open; 0: a new template; otherwise the template's id. */
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function mount(): void
    {
        SaveQuoteTemplate::authorize($this->user(), $this->company());
    }

    public function create(): void
    {
        $this->editingId = 0;
        $this->form = ['name' => '', 'intro_text' => '', 'terms_text' => '', 'validity_days' => '30'];
        $this->rows = [$this->blankRow()];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $template = QuoteTemplate::query()->with('rows.article')->findOrFail($id);
        $this->editingId = $template->id;
        $this->form = [
            'name' => $template->name,
            'intro_text' => (string) $template->intro_text,
            'terms_text' => (string) $template->terms_text,
            'validity_days' => (string) $template->validity_days,
        ];
        $this->rows = $template->rows->map(fn (QuoteTemplateRow $row): array => [
            'article' => (string) $row->article?->number,
            'description' => (string) $row->description,
            'quantity' => str_replace('.', ',', rtrim(rtrim($row->quantity, '0'), '.')),
            'unit' => (string) $row->unit,
            'price' => $row->article_id === null ? Money::ofOre($row->unit_price_ore)->format(withCurrency: false) : '',
            'vat_rate' => (string) $row->vat_rate,
            'discount' => $row->discount_bp === 0 ? '' : sprintf('%d,%02d', intdiv($row->discount_bp, 100), $row->discount_bp % 100),
        ])->values()->all();
        $this->resetErrorBag();
    }

    public function addRow(): void
    {
        if (count($this->rows) < QuoteTemplate::MAX_ROWS) {
            $this->rows[] = $this->blankRow();
        }
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function save(SaveQuoteTemplate $save): void
    {
        $this->resetErrorBag();
        $template = $this->editingId ? QuoteTemplate::query()->findOrFail($this->editingId) : null;

        $saved = $save($this->user(), $this->company(), [...$this->form, 'validity_days' => (int) ($this->form['validity_days'] ?? 0), 'rows' => $this->rows], $template);

        $this->cancel();
        $this->toast(__('Mallen :name är sparad.', ['name' => $saved->name]));
    }

    public function archive(int $id, bool $archived, SaveQuoteTemplate $save): void
    {
        $template = QuoteTemplate::query()->findOrFail($id);
        $save->archive($this->user(), $this->company(), $template, $archived);

        $this->toast($archived ? __('Mallen är arkiverad.') : __('Mallen är återställd.'));
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'form', 'rows');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('crm::livewire.quote-templates', [
            'templates' => QuoteTemplate::query()
                ->when($this->archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
                ->withCount('rows')
                ->orderBy('name')
                ->get(),
            'articles' => $this->editingId === null ? collect() : Article::query()->where('is_active', true)->orderBy('number')->limit(2000)->get(['id', 'number', 'name', 'unit', 'price_ore', 'vat_rate']),
            'vatRates' => InvoiceCalculator::VAT_RATES,
        ]);
    }

    /** @return array<string, string> */
    private function blankRow(): array
    {
        return ['article' => '', 'description' => '', 'quantity' => '1', 'unit' => '', 'price' => '', 'vat_rate' => '2500', 'discount' => ''];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
