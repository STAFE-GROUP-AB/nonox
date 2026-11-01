<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Domain\Accounting\VoucherStatus;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * Verifikationer: by series and number ("A47", "A 47", "47"), description,
 * amount on any row ("8750", "8 750,00 kr", "> 10000 kr") and date.
 */
final class VouchersProvider extends DatabaseSearchProvider
{
    public const string KEY = 'vouchers';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Verifikationer');
    }

    public function order(): int
    {
        return 10;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', Voucher::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        $status = match ($query->status) {
            SearchQuery::DRAFT => VoucherStatus::Draft,
            SearchQuery::POSTED => VoucherStatus::Posted,
            null => null,
            default => false,
        };

        if ($status === false || ! $this->wants($query, amounts: true, dates: true, statuses: true)) {
            return null;
        }

        $companyId = $context->company->id;
        $builder = Voucher::query()
            ->where('vouchers.company_id', $companyId)
            ->select('vouchers.*')
            ->with('series:id,code')
            ->withSum('rows as debit_total', 'debit_ore');

        $rowAmount = fn (?int $min, ?int $max): array => $this->rowAmount($companyId, $min, $max);
        $identifiers = [];

        if ($query->number !== null && $query->series !== null) {
            $identifiers[] = ['vouchers.number = ? AND vouchers.series_id IN (SELECT id FROM voucher_series WHERE company_id = ? AND code = ?)', [$query->number, $companyId, $query->series]];
        } elseif ($query->number !== null) {
            $identifiers[] = ['vouchers.number = ?', [$query->number]];
        }

        if ($query->impliedAmount !== null) {
            [$sql, $bindings] = $rowAmount($query->impliedAmount, $query->impliedAmount);
            $identifiers[] = [$sql, $bindings, 300];
        }

        $text = $query->number === null && $query->impliedAmount === null ? $query : new SearchQuery($query->raw);
        $this->whereText($builder, ['vouchers.description'], $text, $this->anyOf($identifiers));
        $this->selectRank($builder, ['vouchers.description'], $text, $identifiers);

        if ($query->hasAmount()) {
            [$sql, $bindings] = $rowAmount($query->amountMin, $query->amountMax);
            $builder->whereRaw($sql, $bindings);
        }

        $this->whereDate($builder, 'vouchers.voucher_date', $query);

        if ($status instanceof VoucherStatus) {
            $builder->where('vouchers.status', $status->value);
        }

        [$vouchers, $total] = $this->fetch($builder, 'vouchers.voucher_date', $limit);

        $results = $vouchers->map(fn (Voucher $voucher): SearchResult => $this->result($voucher)->withScore(self::rank($voucher)))->values()->all();
        $more = trim($query->text) !== '' && ! $query->hasAmount() && ! $query->hasDate() ? route('accounting.vouchers.index', ['sok' => $query->text, 'ar' => '']) : route('accounting.vouchers.index');

        return $this->group($results, $total, $more);
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            return null;
        }

        $voucher = Voucher::query()->with('series:id,code')->withSum('rows as debit_total', 'debit_ore')->find($id);

        return $voucher instanceof Voucher ? $this->result($voucher) : null;
    }

    public function todo(SearchContext $context): array
    {
        if (! $context->can('vouchers:post')) {
            return [];
        }

        $drafts = Voucher::query()->where('status', VoucherStatus::Draft->value)->count();

        if ($drafts === 0) {
            return [];
        }

        return [new SearchResult(
            type: 'todo',
            id: 'voucher-drafts',
            title: trans_choice(':count verifikationsutkast att bokföra|:count verifikationsutkast att bokföra', $drafts, ['count' => $drafts]),
            url: route('accounting.vouchers.index', ['visa' => 'utkast']),
            icon: 'voucher',
            remember: false,
        )];
    }

    public function detailRoutes(): array
    {
        return ['accounting.vouchers.show' => 'voucher', 'accounting.vouchers.edit' => 'voucher'];
    }

    private function result(Voucher $voucher): SearchResult
    {
        $debit = (int) $voucher->getAttribute('debit_total');

        return new SearchResult(
            type: self::KEY,
            id: (string) $voucher->id,
            title: $voucher->number === null ? __('Utkast') : $voucher->reference(),
            url: route('accounting.vouchers.show', $voucher),
            subtitle: trim($voucher->voucher_date->toDateString().' · '.$voucher->description, ' ·'),
            meta: Money::ofOre($debit)->format(),
            badge: self::when($voucher->number === null, __('Utkast')),
            icon: 'voucher',
        );
    }

    /**
     * Vouchers with a row of the amount, on either side.
     *
     * @return array{0: literal-string, 1: list<int>}
     */
    private function rowAmount(int $companyId, ?int $min, ?int $max): array
    {
        [$condition, $values] = match (true) {
            $min !== null && $max !== null && $min === $max => ['(debit_ore = ? OR credit_ore = ?)', [$min, $min]],
            $min !== null && $max !== null => ['(debit_ore BETWEEN ? AND ? OR credit_ore BETWEEN ? AND ?)', [$min, $max, $min, $max]],
            $min !== null => ['(debit_ore >= ? OR credit_ore >= ?)', [$min, $min]],
            default => ['((debit_ore > 0 AND debit_ore <= ?) OR (credit_ore > 0 AND credit_ore <= ?))', [(int) $max, (int) $max]],
        };

        return [
            'vouchers.id IN (SELECT voucher_id FROM voucher_rows WHERE company_id = ? AND '.$condition.')',
            [$companyId, ...$values],
        ];
    }
}
