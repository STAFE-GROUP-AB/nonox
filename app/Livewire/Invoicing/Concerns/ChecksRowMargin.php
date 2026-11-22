<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing\Concerns;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Pricing\DocumentMargin;
use App\Domain\Pricing\MarginReport;
use App\Domain\Pricing\PriceFloor;
use App\Domain\Pricing\PriceSource;
use App\Models\Article;
use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Margin and floor warnings on the rows of an invoice, quote or order being
 * written (ADR-082 §9, P7). The rows live in the browser, so the browser asks
 * marginCheck() with the rows as they stand, a moment after they change; the
 * server does the arithmetic (one answer for the editor and for what the
 * document does when it is sent) and answers in a fixed number of queries
 * whatever the number of rows.
 *
 * Cost and contribution are internal: they are told only to a person with
 * prices:view-cost. A person without it still learns that a manual price is
 * under the company's floor, since that decides whether the document can be
 * sent, but never what the cost is. Warnings never block here; the block, when
 * the company requires approval, is in PriceFloorGuard when the document is
 * sent or issued.
 *
 * @mixin Component
 */
trait ChecksRowMargin
{
    /** @var array<string, mixed> what the check said about the rows when the document was opened, so the warnings are there before the browser has asked */
    #[Locked]
    public array $marginAtOpen = [];

    /** The most rows one check reads. */
    private const int MARGIN_ROW_LIMIT = 500;

    /** Whether the editor has anything to say: a person who sees costs, or a company with a floor. */
    protected function marginEnabled(): bool
    {
        $company = $this->marginCompany();

        return $company instanceof Company && ($this->canViewCost($company) || PriceFloor::of($company)->isOn());
    }

    /** Fills $marginAtOpen from the rows a saved document has; nothing for a person who has nothing to see. */
    protected function openMargin(): void
    {
        $this->marginAtOpen = $this->marginEnabled() ? $this->marginCheck($this->rows) : [];
    }

    /** Whether the person may see cost, margin and contribution (prices:view-cost). */
    protected function canViewCost(?Company $company = null): bool
    {
        $company ??= $this->marginCompany();
        $user = Auth::user();

        return $company instanceof Company && $user instanceof User && $user->hasTeamPermission($company, 'prices:view-cost');
    }

    /**
     * @param  array<int, mixed>  $rows  the editor's rows as they stand
     * @return array{rows: array<int, array{below_cost: bool, below_floor: bool, needs_approval: bool, text: string}>, totals: ?array{net: string, cost: string, contribution: string, margin: string, uncosted: int}, banner: string, note: string}
     */
    #[Renderless]
    public function marginCheck(array $rows): array
    {
        $empty = ['rows' => [], 'totals' => null, 'banner' => '', 'note' => ''];
        $company = $this->marginCompany();

        if (! $company instanceof Company || ! $this->marginEnabled()) {
            return $empty;
        }

        $viewCost = $this->canViewCost($company);
        [$lines, $indexes] = $this->marginLines(array_slice(array_filter($rows, is_array(...)), 0, self::MARGIN_ROW_LIMIT, true));

        // Another currency: no margin, but a typed price on an article row still needs the approval the company requires.
        if (strtoupper(trim($this->currency)) !== 'SEK') {
            $typed = [];

            foreach ($lines as $position => $line) {
                if ($line['manual'] && ($line['article_id'] !== null || $line['lowers'])) {
                    $typed[] = $indexes[$position] + 1;
                }
            }

            return [
                ...$empty,
                'banner' => PriceFloor::of($company)->requiresApproval() && $typed !== []
                    ? __('Priset på rad :lines är skrivet för hand i en annan valuta än kronor, där marginalen inte kan kontrolleras. Dokumentet kan inte skickas eller skapas förrän någon med behörighet har godkänt priserna.', ['lines' => implode(', ', $typed)])
                    : '',
                'note' => $viewCost ? __('Marginalen beräknas bara för dokument i kronor.') : '',
            ];
        }

        if ($lines === []) {
            return $empty;
        }

        return MarginReport::for($company, $lines, $indexes, $viewCost);
    }

    /**
     * The editor's rows as the margin check reads them: the filled article and
     * free-text rows with a readable quantity and price. Two queries.
     *
     * @param  array<int|string, array<mixed>>  $rows
     * @return array{0: list<array{article_id: ?int, quantity: string, unit: string, net_ore: int, manual: bool, lowers: bool}>, 1: list<int>} the lines, and the editor's row index of each
     */
    private function marginLines(array $rows): array
    {
        $numbers = array_values(array_unique(array_filter(array_map(fn (array $row): string => trim((string) ($row['article'] ?? '')), $rows))));
        $articles = $numbers === [] ? collect() : Article::query()->whereIn('number', $numbers)->pluck('id', 'number');
        $units = Unit::query()->pluck('code', 'id');
        $lines = [];
        $indexes = [];

        foreach ($rows as $index => $row) {
            $quantity = InvoiceCalculator::quantity((string) ($row['quantity'] ?? ''));
            $discount = InvoiceCalculator::discount((string) ($row['discount'] ?? ''));
            $typed = trim((string) ($row['price'] ?? ''));

            if ($quantity === null || $discount === null || $typed === '') {
                continue;
            }

            try {
                $price = Money::parse($typed);
            } catch (InvalidArgumentException) {
                continue;
            }

            $number = trim((string) ($row['article'] ?? ''));
            $net = InvoiceCalculator::rowNet($price, $quantity, $discount)->ore;
            $lines[] = [
                'article_id' => $number === '' ? null : $articles->get($number),
                'quantity' => $quantity,
                'unit' => (string) $units->get((int) ($row['unit_id'] ?? 0), ''),
                'net_ore' => $net,
                'lowers' => $net < 0 || $discount > 0,
                'manual' => DocumentMargin::isManual($number === '' ? null : (int) $articles->get($number), ($row['price_source'] ?? '') === 'MANUAL' ? PriceSource::Manual : null, $discount),
            ];
            $indexes[] = (int) $index;
        }

        return [$lines, $indexes];
    }

    private function marginCompany(): ?Company
    {
        $user = Auth::user();

        return $user instanceof User ? $user->currentCompany() : null;
    }
}
