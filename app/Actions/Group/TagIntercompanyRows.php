<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanyKindRule;
use App\Domain\Group\IntercompanyLedgerState;
use App\Domain\Group\IntercompanyOrgNumber;
use App\Domain\Group\IntercompanyRowSelection;
use App\Domain\Group\IntercompanyTagSource;
use App\Domain\Group\MarkKind;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyGroupMark;
use App\Models\IntercompanyRowTag;
use App\Models\User;
use App\Policies\IntercompanyPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bulk tagging of manual vouchers (loans, interest, dividends, group
 * contributions; ADR-060, addendum 2026-11-07, 2.3): "tag the rows on
 * account X from A to B with group company Y as kind K". preview() shows
 * how many rows and which amounts; a person confirms. Rows that already
 * have a tag keep it. The default kind is the account's mark in the user's
 * own overview (source ACCOUNT_MARK when kept), else a suggestion from the
 * BAS number or the account name; a kind the person picks is MANUAL.
 */
final class TagIntercompanyRows
{
    private const CHUNK = 500;

    /**
     * @param  array<string, mixed>  $input  account, from, to
     * @return array{rows: int, tagged: int, debit_ore: int, credit_ore: int, in_closed_year: int, in_locked_period: int, warning: ?string}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function preview(User $user, Company $company, array $input): array
    {
        if (! $user->hasTeamPermission($company, 'vouchers:view')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($company, $input): array {
            $selection = IntercompanyRowSelection::fromInput($input);
            $row = $selection->rows()
                ->leftJoin('intercompany_row_tags', 'intercompany_row_tags.voucher_row_id', '=', 'voucher_rows.id')
                ->toBase()
                ->selectRaw('COUNT(*) AS rows, COUNT(intercompany_row_tags.id) AS tagged, COALESCE(SUM(voucher_rows.debit_ore), 0) AS debit_ore, COALESCE(SUM(voucher_rows.credit_ore), 0) AS credit_ore')
                ->first();

            return [
                'rows' => (int) ($row->rows ?? 0),
                'tagged' => (int) ($row->tagged ?? 0),
                'debit_ore' => (int) ($row->debit_ore ?? 0),
                'credit_ore' => (int) ($row->credit_ore ?? 0),
                ...IntercompanyLedgerState::counts($company, $selection->rows()),
                'warning' => self::accountWarning($selection->account->number),
            ];
        });
    }

    /**
     * The kind to offer for an account, and whether it comes from the account's mark.
     *
     * @return array{kind: ?IntercompanyKind, from_mark: bool}
     */
    public function defaultKind(User $user, Company $company, int $accountNumber): array
    {
        return CurrentCompany::run($company, function () use ($user, $company, $accountNumber): array {
            $mark = $this->markOf($user, $company, $accountNumber);

            if ($mark !== null) {
                return ['kind' => IntercompanyKind::fromMark($mark), 'from_mark' => true];
            }

            $account = Account::query()->where('number', $accountNumber)->first(['number', 'name']);

            return ['kind' => $account === null ? null : IntercompanyKindRule::suggest($account->number, $account->name), 'from_mark' => false];
        });
    }

    /**
     * @param  array<string, mixed>  $input  account, from, to, counterparty_org_number, kind
     * @return array{tagged: int, skipped: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, array $input): array
    {
        if (! IntercompanyPolicy::mayWrite($user, $company)) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $input): array {
            IntercompanyLedgerState::lock($company);
            $selection = IntercompanyRowSelection::fromInput($input);
            $org = IntercompanyOrgNumber::validated($company, $input['counterparty_org_number'] ?? '', 'counterparty_org_number');
            $kind = $this->kind($input['kind'] ?? null);
            $source = $this->markOf($user, $company, $selection->account->number)?->value === $kind->value
                ? IntercompanyTagSource::AccountMark
                : IntercompanyTagSource::Manual;

            $rowIds = $selection->rows()
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('intercompany_row_tags')->whereColumn('intercompany_row_tags.voucher_row_id', 'voucher_rows.id'))
                ->orderBy('voucher_rows.id')
                ->pluck('voucher_rows.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $all = $selection->rows()->count();
            $flags = IntercompanyLedgerState::counts($company, $selection->rows()->whereNotExists(fn ($query) => $query->selectRaw('1')->from('intercompany_row_tags')->whereColumn('intercompany_row_tags.voucher_row_id', 'voucher_rows.id')));

            $now = now();

            foreach (array_chunk($rowIds, self::CHUNK) as $chunk) {
                IntercompanyRowTag::query()->insert(array_map(fn (int $rowId): array => [
                    'company_id' => $company->id,
                    'voucher_row_id' => $rowId,
                    'counterparty_org_number' => $org,
                    'kind' => $kind->value,
                    'source' => $source->value,
                    'tagged_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $result = ['tagged' => count($rowIds), 'skipped' => $all - count($rowIds)];

            Audit::record('intercompany_tags.tagged', $selection->account, [
                ...$result,
                'account' => $selection->account->number,
                'from' => $selection->from->toDateString(),
                'to' => $selection->to->toDateString(),
                'kind' => $kind->value,
                'source' => $source->value,
                ...$flags,
            ], $company, $user);

            return $result;
        }));
    }

    /**
     * A warning for accounts that are rarely intra-group items in themselves:
     * bank, VAT and exchange differences. Shown before tagging; not a refusal.
     */
    public static function accountWarning(int $account): ?string
    {
        return match (true) {
            $account >= 1900 && $account <= 1999 => (string) __('Kontot är ett bank- eller kassakonto. Sådana rader är sällan ett mellanhavande inom koncernen i sig; kontrollera att du har valt rätt konto.'),
            $account >= 2600 && $account <= 2699 => (string) __('Kontot är ett momskonto. Moms är ett mellanhavande med Skatteverket, inte med koncernföretaget; kontrollera att du har valt rätt konto.'),
            ($account >= 3960 && $account <= 3969) || ($account >= 7960 && $account <= 7969) => (string) __('Kontot gäller kursdifferenser. De är sällan ett mellanhavande inom koncernen i sig; kontrollera att du har valt rätt konto.'),
            default => null,
        };
    }

    private function kind(mixed $value): IntercompanyKind
    {
        validator(['kind' => $value], ['kind' => ['required', Rule::enum(IntercompanyKind::class)]], [
            'kind.required' => __('Välj vad raderna är.'),
        ])->validate();

        return IntercompanyKind::from((string) $value);
    }

    /**
     * The account's mark in the user's own overview groups (ADR-060 G.1), when
     * all of them agree; the user's own data, so no other user's mark is read.
     */
    private function markOf(User $user, Company $company, int $accountNumber): ?MarkKind
    {
        $kinds = CompanyGroupMark::query()
            ->join('company_groups', 'company_groups.id', '=', 'company_group_marks.company_group_id')
            ->where('company_groups.user_id', $user->id)
            ->where('company_group_marks.company_id', $company->id)
            ->where('company_group_marks.account_number', $accountNumber)
            ->distinct()
            ->pluck('company_group_marks.kind');

        return $kinds->count() === 1 ? MarkKind::tryFrom((string) $kinds->first()) : null;
    }
}
