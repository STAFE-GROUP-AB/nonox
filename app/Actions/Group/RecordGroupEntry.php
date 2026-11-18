<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\Consolidation;
use App\Domain\Group\EliminationSuggestions;
use App\Domain\Group\EliminationType;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAccessLevel;
use App\Domain\Group\GroupAuditTrail;
use App\Domain\Group\GroupEntryKind;
use App\Domain\Group\GroupEntryOrigin;
use App\Models\CorporateGroup;
use App\Models\GroupEntry;
use App\Models\GroupEntryRow;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\DimensionScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Writes the group ledger (ADR-060, addendum 2026-11-07, 2.4; ADR-081 E25,
 * S2-1.5): accepts an elimination suggestion, records a manual entry, or
 * reverses an entry. Only whoever administers the group (owner or
 * administrator in every member and the parent, live) writes, with FULL
 * access for the period. Nothing is ever written into a member company's
 * books: the entry lives in group_entries only.
 *
 * Every write takes the group's row lock, re-checks administration with
 * fresh memberships, numbers the entry gapless per group, chains its hash to
 * the group's previous entry, and logs it in each company the entry names
 * (counts and enums only). An acceptance is bound to the suggestion the
 * person saw: it is recomputed under the lock and refused when its
 * fingerprint differs. A mistake is never changed: it is reversed by a new
 * entry, as a posted voucher is corrected.
 */
final class RecordGroupEntry
{
    /** At most this many rows in a manual entry. */
    public const int MAX_ROWS = 50;

    public function __construct(private readonly EliminationSuggestions $suggestions) {}

    /**
     * @param  array<string, mixed>  $input  from, to, key and fingerprint of the suggestion, optional reason
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function accept(User $user, int $groupId, array $input): GroupEntry
    {
        [$from, $to] = ConsolidateGroup::period($input);
        $data = Validator::make($input, [
            'key' => ['required', 'string', 'max:120'],
            'fingerprint' => ['required', 'string', 'size:64'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'key.*' => __('Välj ett förslag att godkänna.'),
            'fingerprint.*' => __('Förslaget har ändrats sedan sidan visades. Ladda om sidan och granska det igen.'),
            'reason.max' => __('Kommentaren får vara högst 255 tecken.'),
        ])->validate();

        return $this->locked($user, $groupId, $from, function (CorporateGroup $group, GroupAccess $access) use ($user, $from, $to, $data): GroupEntry {
            $suggestion = collect($this->suggestions->compute($access, $group, $from, $to)['suggestions'])->firstWhere('key', $data['key']);

            if ($suggestion === null || ! hash_equals($suggestion['fingerprint'], (string) $data['fingerprint'])) {
                throw ValidationException::withMessages(['key' => __('Förslaget har ändrats sedan sidan visades. Ladda om sidan och granska det igen.')]);
            }

            if ($suggestion['status'] !== EliminationSuggestions::MATCHED) {
                throw ValidationException::withMessages(['key' => match ($suggestion['status']) {
                    EliminationSuggestions::NOTHING => __('Förslaget har inget att eliminera.'),
                    EliminationSuggestions::NEEDS_REVIEW => __('Förslaget gäller ett konto med en ingående balans utan koncernmarkering och kan inte godkännas. Granska balansen och gör en manuell post.'),
                    EliminationSuggestions::NEEDS_ACQUISITION_ANALYSIS => __('Förslaget kräver en förvärvsanalys och kan inte godkännas i den interna rapporten.'),
                    default => __('Förslaget har en differens och kan inte godkännas som det är. Rätta markeringarna eller bokföringen, eller gör en manuell post.'),
                }]);
            }

            if ($suggestion['accepted_entry'] !== null) {
                throw ValidationException::withMessages(['key' => __('Förslaget är redan godkänt för perioden (post :number). Återför posten först om den ska göras om.', ['number' => $suggestion['accepted_entry']])]);
            }

            /** @var EliminationType $type */
            $type = $suggestion['type'];

            return $this->write($user, $group, $from, $to, [
                'kind' => $type->kind(),
                'type' => $type,
                'origin' => GroupEntryOrigin::Suggestion,
                'suggestion_key' => $suggestion['key'],
                'reason' => self::text($data['reason'] ?? null),
                'source' => [
                    'fingerprint' => $suggestion['fingerprint'],
                    'credit' => $suggestion['credit'],
                    'debit' => $suggestion['debit'],
                ],
            ], $suggestion['rows']);
        });
    }

    /**
     * @param  array<string, mixed>  $input  from, to, type, reason and rows (company, account, debit, credit)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function manual(User $user, int $groupId, array $input): GroupEntry
    {
        [$from, $to] = ConsolidateGroup::period($input);
        $data = Validator::make($input, [
            'type' => ['required', Rule::enum(EliminationType::class)],
            'reason' => ['required', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:2', 'max:'.self::MAX_ROWS],
            'rows.*.company' => ['required', 'integer'],
            'rows.*.account' => ['required', 'integer', 'between:1000,8999'],
            'rows.*.debit' => ['nullable', 'string', 'max:20'],
            'rows.*.credit' => ['nullable', 'string', 'max:20'],
        ], [
            'type.*' => __('Välj vilket slag av post det är.'),
            'reason.required' => __('Skriv varför posten görs.'),
            'reason.max' => __('Motiveringen får vara högst 255 tecken.'),
            'rows.required' => __('En post behöver minst två rader.'),
            'rows.min' => __('En post behöver minst två rader.'),
            'rows.max' => __('En post kan ha högst :max rader.', ['max' => self::MAX_ROWS]),
            'rows.*.company.*' => __('Välj företaget på varje rad.'),
            'rows.*.account.*' => __('Ange ett koncernkonto mellan 1000 och 8999 på varje rad.'),
            'rows.*.debit.*' => __('Beloppet kan inte läsas.'),
            'rows.*.credit.*' => __('Beloppet kan inte läsas.'),
        ])->validate();

        $rows = array_map(self::manualRow(...), array_values($data['rows']));
        $debit = array_sum(array_column($rows, 'debit_ore'));

        if ($debit !== array_sum(array_column($rows, 'credit_ore'))) {
            throw ValidationException::withMessages(['rows' => __('Posten balanserar inte: debet och kredit måste vara lika stora.')]);
        }

        $type = EliminationType::from((string) $data['type']);

        return $this->locked($user, $groupId, $from, function (CorporateGroup $group, GroupAccess $access) use ($user, $from, $to, $data, $rows, $type): GroupEntry {
            $companies = app(Consolidation::class)->gather($access, $group, $from, $to)['read'];
            $ids = array_map(fn (array $row): int => $row['company']->id, $companies);

            foreach ($rows as $row) {
                if (! in_array($row['company_id'], $ids, true)) {
                    throw ValidationException::withMessages(['rows' => __('Ett företag på raderna ingår inte i konsolideringen för perioden.')]);
                }
            }

            return $this->write($user, $group, $from, $to, [
                'kind' => $type->kind(),
                'type' => $type,
                'origin' => GroupEntryOrigin::Manual,
                'suggestion_key' => null,
                'reason' => self::text($data['reason']),
                'source' => null,
            ], $rows);
        });
    }

    /**
     * @param  array<string, mixed>  $input  reason
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reverse(User $user, int $groupId, int $entryId, array $input): GroupEntry
    {
        $data = Validator::make($input, [
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => __('Skriv varför posten återförs.'),
            'reason.max' => __('Motiveringen får vara högst 255 tecken.'),
        ])->validate();

        $group = GroupAccess::for($user)->corporateGroup($groupId);
        $original = GroupEntry::query()->where('corporate_group_id', $group->id)->findOrFail($entryId);

        return $this->locked($user, $groupId, $original->period_from, function (CorporateGroup $group, GroupAccess $access) use ($user, $original, $data): GroupEntry {
            // An entry that names a company the user no longer sees (a former member) is not theirs to reverse (cold review E25, P1-2).
            if (in_array(false, self::visibleCompanies($access, $original->rows()->pluck('company_id')->all()), true)) {
                throw new AuthorizationException(__('Posten gäller ett företag som du inte har åtkomst till och kan inte återföras av dig.'));
            }

            if ($original->kind === GroupEntryKind::Reversal) {
                throw ValidationException::withMessages(['entry' => __('En återföring kan inte återföras. Gör en ny post i stället.')]);
            }

            if (GroupEntry::query()->where('reverses_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['entry' => __('Posten är redan återförd.')]);
            }

            $rows = array_values($original->rows()->get()->map(fn (GroupEntryRow $row): array => [
                'company_id' => $row->company_id,
                'account_number' => $row->account_number,
                'group_account_number' => $row->group_account_number,
                'debit_ore' => $row->credit_ore,
                'credit_ore' => $row->debit_ore,
            ])->all());

            return $this->write($user, $group, $original->period_from, $original->period_to, [
                'kind' => GroupEntryKind::Reversal,
                'type' => $original->type,
                'origin' => GroupEntryOrigin::Reversal,
                'reverses_id' => $original->id,
                'suggestion_key' => null,
                'reason' => self::text($data['reason']),
                'source' => null,
            ], $rows);
        });
    }

    /**
     * The group lock, then a fresh check that the user administers the
     * group and has FULL access for the period.
     *
     * @template T
     *
     * @param  callable(CorporateGroup, GroupAccess): T  $write
     * @return T
     *
     * @throws AuthorizationException
     */
    private function locked(User $user, int $groupId, CarbonImmutable $from, callable $write): mixed
    {
        self::authorize(GroupAccess::for($user), $groupId, $from);

        return DB::transaction(function () use ($user, $groupId, $from, $write): mixed {
            CorporateGroup::query()->whereKey($groupId)->lockForUpdate()->first();
            // With fresh memberships under the lock: a membership that ended a moment ago no longer counts.
            $access = GroupAccess::for($user->fresh() ?? $user);
            $group = self::authorize($access, $groupId, $from);

            return $write($group, $access);
        });
    }

    /**
     * Per company id: whether the user may see that company's figures
     * (reports:view, archived and module rules included) without being
     * limited to cost centres or projects there. A group ledger entry is
     * shown or reversed only when this holds for every company on its rows;
     * a member that has left the group no longer counts towards FULL access,
     * but its entries stay in the ledger (cold review E25, P1-2).
     *
     * @param  array<array-key, mixed>  $companyIds
     * @return array<int, bool>
     */
    public static function visibleCompanies(GroupAccess $access, array $companyIds): array
    {
        $visible = [];

        foreach (array_unique(array_map(fn (mixed $id): int => (int) $id, $companyIds)) as $companyId) {
            $visible[$companyId] = $access->mayView($companyId) && ! DimensionScope::limits($access->user, $companyId);
        }

        return $visible;
    }

    /** @throws AuthorizationException */
    private static function authorize(GroupAccess $access, int $groupId, CarbonImmutable $from): CorporateGroup
    {
        $group = $access->corporateGroup($groupId);

        if (ConsolidateGroup::level($access, $group, $from) !== GroupAccessLevel::Full || ! $access->administers($group)) {
            throw new AuthorizationException(__('Bara den som är ägare eller administratör i alla företag i koncernen kan göra poster i koncernjournalen.'));
        }

        return $group;
    }

    /**
     * @param  array{kind: GroupEntryKind, type: EliminationType, origin: GroupEntryOrigin, reverses_id?: int, suggestion_key: ?string, reason: ?string, source: ?array<string, mixed>}  $entry
     * @param  list<array{company_id: int, account_number: ?int, group_account_number: int, debit_ore: int, credit_ore: int}>  $rows
     */
    private function write(User $user, CorporateGroup $group, CarbonImmutable $from, CarbonImmutable $to, array $entry, array $rows): GroupEntry
    {
        $total = array_sum(array_column($rows, 'debit_ore'));

        if (count($rows) < 2 || $total <= 0 || $total !== array_sum(array_column($rows, 'credit_ore'))) {
            throw ValidationException::withMessages(['rows' => __('Posten balanserar inte: debet och kredit måste vara lika stora.')]);
        }

        $previous = GroupEntry::query()->where('corporate_group_id', $group->id)->orderByDesc('number')->first(['id', 'number', 'hash']);
        $acceptedAt = CarbonImmutable::now();
        $values = [
            'corporate_group_id' => $group->id,
            'number' => ($previous->number ?? 0) + 1,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'entry_date' => $to->toDateString(),
            'kind' => $entry['kind']->value,
            'type' => $entry['type']->value,
            'origin' => $entry['origin']->value,
            'reverses_id' => $entry['reverses_id'] ?? null,
            'suggestion_key' => $entry['suggestion_key'],
            'reason' => $entry['reason'],
            'source' => $entry['source'],
            'rows_count' => count($rows),
            'total_ore' => $total,
            'accepted_by' => $user->id,
            'accepted_at' => $acceptedAt->format('Y-m-d H:i:sP'),
            'previous_hash' => $previous?->hash,
        ];
        $values['hash'] = GroupEntry::chainHash($values['previous_hash'], GroupEntry::content($values, $rows));

        $created = GroupEntry::create($values);

        foreach ($rows as $row) {
            GroupEntryRow::create(['group_entry_id' => $created->id, ...$row]);
        }

        // Not bookkeeping in any company: each company the entry names learns that it was eliminated within
        // the group, with ids, enums and counts, never another member's name or the reason (free text).
        GroupAuditTrail::record(match ($entry['origin']) {
            GroupEntryOrigin::Suggestion => 'group_entry.accepted',
            GroupEntryOrigin::Manual => 'group_entry.recorded',
            GroupEntryOrigin::Reversal => 'group_entry.reversed',
        }, $group, array_column($rows, 'company_id'), $user, [
            'group_entry_id' => $created->id,
            'number' => $created->number,
            'kind' => $entry['kind']->value,
            'type' => $entry['type']->value,
            'reverses_id' => $entry['reverses_id'] ?? null,
            'rows' => count($rows),
        ]);

        return $created;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{company_id: int, account_number: null, group_account_number: int, debit_ore: int, credit_ore: int}
     *
     * @throws ValidationException
     */
    private static function manualRow(array $row): array
    {
        $debit = self::amount($row['debit'] ?? null);
        $credit = self::amount($row['credit'] ?? null);

        if (($debit > 0) === ($credit > 0)) {
            throw ValidationException::withMessages(['rows' => __('Varje rad ska ha ett belopp i antingen debet eller kredit.')]);
        }

        return [
            'company_id' => (int) $row['company'],
            'account_number' => null,
            'group_account_number' => (int) $row['account'],
            'debit_ore' => $debit,
            'credit_ore' => $credit,
        ];
    }

    /** @throws ValidationException */
    private static function amount(mixed $value): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }

        try {
            $ore = Money::parse((string) $value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['rows' => __('Beloppet :amount kan inte läsas. Skriv till exempel 1 234,50.', ['amount' => (string) $value])]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages(['rows' => __('Skriv beloppen utan minustecken, i debet eller kredit.')]);
        }

        return $ore;
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
