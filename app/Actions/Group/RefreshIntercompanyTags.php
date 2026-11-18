<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanyKindRule;
use App\Domain\Group\IntercompanyLedgerState;
use App\Domain\Group\IntercompanySide;
use App\Domain\Group\IntercompanyTagPlan;
use App\Domain\Group\IntercompanyTagSource;
use App\Models\Company;
use App\Models\IntercompanyRowTag;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VoucherRow;
use App\Policies\IntercompanyPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * "Uppdatera koncernmarkeringar" (ADR-060, addendum 2026-11-07, 2.3): tags
 * the rows of posted vouchers made from an invoice, a payment or a supplier
 * invoice of a marked customer or supplier, with the kind from
 * IntercompanyKindRule. Idempotent: a second run changes nothing.
 *
 * A correcting voucher (CORRECTION) of such a voucher, and a correction of
 * that one, is followed too: each reversed row gets the same kind and
 * organisation number as the row it reverses (matched on account and the
 * swapped amounts), so the kind nets to zero; a re-booked row gets the kind
 * from the account rule, with the original's organisation number.
 *
 * plan() shows what would change; a person confirms, and only then does
 * __invoke() apply it. Only tags with the source COUNTERPARTY are added,
 * changed or removed (a marking taken away, or a row the rule no longer
 * covers); a row a person tagged by hand keeps its tag.
 *
 * Every query runs inside the company with explicit company_id conditions on
 * the joined tables; the voucher rows themselves are only read.
 */
final class RefreshIntercompanyTags
{
    private const CHUNK = 500;

    /** @throws AuthorizationException */
    public function plan(User $user, Company $company, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): IntercompanyTagPlan
    {
        if (! $user->hasTeamPermission($company, 'vouchers:view')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): IntercompanyTagPlan => $this->compute($company, $from, $to));
    }

    /**
     * @return array{added: int, changed: int, removed: int, kept: int}
     *
     * @throws AuthorizationException
     */
    public function __invoke(User $user, Company $company, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        if (! IntercompanyPolicy::mayWrite($user, $company)) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $from, $to): array {
            // Serialises concurrent runs, bulk tagging and removals in the company.
            IntercompanyLedgerState::lock($company);
            $plan = $this->compute($company, $from, $to);

            if ($plan->isEmpty()) {
                return $plan->counts();
            }

            $now = now();

            foreach (array_chunk($plan->add, self::CHUNK, true) as $chunk) {
                IntercompanyRowTag::query()->insert(array_map(fn (int $rowId, array $tag): array => [
                    'company_id' => $company->id,
                    'voucher_row_id' => $rowId,
                    'counterparty_org_number' => $tag['org'],
                    'kind' => $tag['kind']->value,
                    'source' => IntercompanyTagSource::Counterparty->value,
                    'tagged_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_keys($chunk), array_values($chunk)));
            }

            $groups = [];

            foreach ($plan->change as $tagId => $tag) {
                $groups["{$tag['kind']->value}|{$tag['org']}"][] = $tagId;
            }

            foreach ($groups as $key => $ids) {
                [$kind, $org] = explode('|', $key);

                foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                    IntercompanyRowTag::query()->whereKey($chunk)->update(['kind' => $kind, 'counterparty_org_number' => $org, 'tagged_by' => $user->id, 'updated_at' => $now]);
                }
            }

            foreach (array_chunk($plan->remove, self::CHUNK) as $chunk) {
                IntercompanyRowTag::query()->whereKey($chunk)->delete();
            }

            Audit::record('intercompany_tags.refreshed', null, [
                ...$plan->counts(),
                ...$plan->flags(),
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ], $company, $user);

            return $plan->counts();
        }));
    }

    private function compute(Company $company, ?CarbonImmutable $from, ?CarbonImmutable $to): IntercompanyTagPlan
    {
        /** @var array<int, array{kind: IntercompanyKind, org: string, date: string, closed: bool}> $wanted */
        $wanted = [];
        /** @var array<string, array{side: IntercompanySide, org: string, rows: list<array{account_id: int, debit: int, credit: int, tag: ?array{kind: IntercompanyKind, org: string}}>}> $vouchers */
        $vouchers = [];

        foreach ([IntercompanySide::Customer, IntercompanySide::Supplier] as $side) {
            foreach ($this->candidates($company, $side) as $row) {
                $kind = IntercompanyKindRule::forCounterparty($side, (int) $row->account_number);
                $target = $kind === null ? null : ['kind' => $kind, 'org' => (string) $row->org_number];
                $this->want($wanted, $row, $target);

                $vouchers[(string) $row->voucher_id] ??= ['side' => $side, 'org' => (string) $row->org_number, 'rows' => []];
                $vouchers[(string) $row->voucher_id]['rows'][] = $this->remembered($row, $target);
            }
        }

        $this->followCorrections($company, $wanted, $vouchers);

        $inPeriod = fn (string $date): bool => ($from === null || $date >= $from->toDateString()) && ($to === null || $date <= $to->toDateString());
        $wanted = array_filter($wanted, fn (array $tag): bool => $inPeriod($tag['date']));

        $existing = IntercompanyRowTag::query()
            ->join('voucher_rows', 'voucher_rows.id', '=', 'intercompany_row_tags.voucher_row_id')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('fiscal_years', 'fiscal_years.id', '=', 'vouchers.fiscal_year_id')
            ->where('voucher_rows.company_id', $company->id)
            ->when($from, fn (Builder $query) => $query->where('vouchers.voucher_date', '>=', $from?->toDateString()))
            ->when($to, fn (Builder $query) => $query->where('vouchers.voucher_date', '<=', $to?->toDateString()))
            ->toBase()
            ->get(['intercompany_row_tags.id', 'intercompany_row_tags.voucher_row_id', 'intercompany_row_tags.kind', 'intercompany_row_tags.counterparty_org_number', 'intercompany_row_tags.source', 'vouchers.voucher_date', 'fiscal_years.status AS fiscal_year_status']);

        $add = $wanted;
        $change = [];
        $remove = [];
        $kept = 0;
        $closed = 0;
        $locked = 0;
        $flag = function (string $date, bool $inClosedYear) use ($company, &$closed, &$locked): void {
            $closed += $inClosedYear ? 1 : 0;
            $locked += IntercompanyLedgerState::locked($company, $date) ? 1 : 0;
        };

        foreach ($existing as $tag) {
            $rowId = (int) $tag->voucher_row_id;
            $target = $wanted[$rowId] ?? null;
            unset($add[$rowId]);

            if ($tag->source !== IntercompanyTagSource::Counterparty->value) {
                $kept += $target === null ? 0 : 1;

                continue;
            }

            if ($target === null) {
                $remove[] = (int) $tag->id;
                $flag(substr((string) $tag->voucher_date, 0, 10), IntercompanyLedgerState::closed((string) $tag->fiscal_year_status));

                continue;
            }

            if ($tag->kind !== $target['kind']->value || $tag->counterparty_org_number !== $target['org']) {
                $change[(int) $tag->id] = ['kind' => $target['kind'], 'org' => $target['org']];
                $flag($target['date'], $target['closed']);
            }
        }

        foreach ($add as $target) {
            $flag($target['date'], $target['closed']);
        }

        return new IntercompanyTagPlan(
            array_map(fn (array $target): array => ['kind' => $target['kind'], 'org' => $target['org']], $add),
            $change,
            $remove,
            $kept,
            $closed,
            $locked,
        );
    }

    /**
     * Correcting vouchers of the vouchers found so far, level by level (a
     * correction of a correction too), one query per level.
     *
     * @param  array<int, array{kind: IntercompanyKind, org: string, date: string, closed: bool}>  $wanted
     * @param  array<string, array{side: IntercompanySide, org: string, rows: list<array{account_id: int, debit: int, credit: int, tag: ?array{kind: IntercompanyKind, org: string}}>}>  $vouchers
     */
    private function followCorrections(Company $company, array &$wanted, array &$vouchers): void
    {
        $frontier = array_keys($vouchers);

        while ($frontier !== []) {
            $next = [];

            foreach (array_chunk($frontier, 1000) as $chunk) {
                foreach ($this->rows($company)
                    ->where('vouchers.source', VoucherSource::Correction->value)
                    ->whereIn('vouchers.corrects_voucher_id', $chunk)
                    ->addSelect(['vouchers.corrects_voucher_id'])
                    ->get()
                    ->groupBy('voucher_id') as $voucherId => $rows) {
                    $voucherId = (string) $voucherId;
                    $original = $vouchers[(string) $rows->first()?->corrects_voucher_id] ?? null;

                    if ($original === null || isset($vouchers[$voucherId])) {
                        continue;
                    }

                    $unused = $original['rows'];
                    $entry = ['side' => $original['side'], 'org' => $original['org'], 'rows' => []];

                    foreach ($rows as $row) {
                        $target = null;
                        $matched = false;

                        foreach ($unused as $index => $reversed) {
                            if ($reversed['account_id'] === (int) $row->account_id && $reversed['debit'] === (int) $row->credit_ore && $reversed['credit'] === (int) $row->debit_ore) {
                                $target = $reversed['tag'];
                                $matched = true;
                                unset($unused[$index]);

                                break;
                            }
                        }

                        if (! $matched) {
                            $kind = IntercompanyKindRule::forCounterparty($original['side'], (int) $row->account_number);
                            $target = $kind === null ? null : ['kind' => $kind, 'org' => $original['org']];
                        }

                        $this->want($wanted, $row, $target);
                        $entry['rows'][] = $this->remembered($row, $target);
                    }

                    $vouchers[$voucherId] = $entry;
                    $next[] = $voucherId;
                }
            }

            $frontier = $next;
        }
    }

    /**
     * @param  array<int, array{kind: IntercompanyKind, org: string, date: string, closed: bool}>  $wanted
     * @param  ?array{kind: IntercompanyKind, org: string}  $target
     */
    private function want(array &$wanted, \stdClass $row, ?array $target): void
    {
        if ($target === null) {
            return;
        }

        $wanted[(int) $row->row_id] = [
            ...$target,
            'date' => substr((string) $row->voucher_date, 0, 10),
            'closed' => IntercompanyLedgerState::closed((string) $row->fiscal_year_status),
        ];
    }

    /**
     * What a reversing row of a later correction follows: a tag a person set
     * on the row, else the rule's.
     *
     * @param  ?array{kind: IntercompanyKind, org: string}  $target
     * @return array{account_id: int, debit: int, credit: int, tag: ?array{kind: IntercompanyKind, org: string}}
     */
    private function remembered(\stdClass $row, ?array $target): array
    {
        $own = $row->tag_source !== null && $row->tag_source !== IntercompanyTagSource::Counterparty->value
            ? ['kind' => IntercompanyKind::from((string) $row->tag_kind), 'org' => (string) $row->tag_org]
            : $target;

        return ['account_id' => (int) $row->account_id, 'debit' => (int) $row->debit_ore, 'credit' => (int) $row->credit_ore, 'tag' => $own];
    }

    /**
     * The rows of posted vouchers from an invoice, payment or supplier
     * invoice whose customer or supplier is marked; one query per side.
     *
     * @return iterable<\stdClass>
     */
    private function candidates(Company $company, IntercompanySide $side): iterable
    {
        [$table, $morph, $partyColumn] = $side === IntercompanySide::Customer
            ? ['invoices', (new Invoice)->getMorphClass(), 'customer_id']
            : ['supplier_invoices', (new SupplierInvoice)->getMorphClass(), 'supplier_id'];

        return $this->rows($company)
            ->join($table, fn (JoinClause $join) => $join->whereRaw("{$table}.id::text = vouchers.source_id")->where("{$table}.company_id", $company->id))
            ->join('intercompany_counterparties', fn (JoinClause $join) => $join->on("intercompany_counterparties.{$partyColumn}", '=', "{$table}.{$partyColumn}")->where('intercompany_counterparties.company_id', $company->id))
            ->whereIn('vouchers.source', [VoucherSource::Invoice->value, VoucherSource::Payment->value, VoucherSource::SupplierInvoice->value])
            ->where('vouchers.source_type', $morph)
            ->addSelect(['intercompany_counterparties.counterparty_org_number AS org_number'])
            ->get();
    }

    /** Posted rows of the company with their account, date, year status and any tag. */
    private function rows(Company $company): \Illuminate\Database\Query\Builder
    {
        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->join('fiscal_years', 'fiscal_years.id', '=', 'vouchers.fiscal_year_id')
            ->leftJoin('intercompany_row_tags', fn (JoinClause $join) => $join->on('intercompany_row_tags.voucher_row_id', '=', 'voucher_rows.id')->where('intercompany_row_tags.company_id', $company->id))
            ->where('vouchers.company_id', $company->id)
            ->where('accounts.company_id', $company->id)
            ->where('fiscal_years.company_id', $company->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->orderBy('voucher_rows.id')
            ->toBase()
            ->select([
                'voucher_rows.id AS row_id', 'voucher_rows.voucher_id', 'voucher_rows.account_id', 'voucher_rows.debit_ore', 'voucher_rows.credit_ore',
                'accounts.number AS account_number', 'vouchers.voucher_date', 'fiscal_years.status AS fiscal_year_status',
                'intercompany_row_tags.kind AS tag_kind', 'intercompany_row_tags.counterparty_org_number AS tag_org', 'intercompany_row_tags.source AS tag_source',
            ]);
    }
}
