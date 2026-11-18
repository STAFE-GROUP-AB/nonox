<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Group\Consolidation;
use App\Domain\Group\EliminationType;
use App\Domain\Group\GroupEntryKind;
use App\Domain\Group\GroupEntryOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One entry in a shared group's ledger (ADR-060, addendum 2026-11-07, 2.4;
 * ADR-081 E25, S2-1.5): an accepted elimination, a manual entry or a
 * reversal, numbered gapless per group, for one consolidation period. It
 * applies to a consolidation run of exactly that period. It is never
 * written into any company's books and is not räkenskapsinformation of any
 * legal person. Append-only: the model refuses changes, PostgreSQL triggers
 * refuse UPDATE and DELETE and check at commit that the rows balance. Each
 * entry's hash covers its content and the previous entry's hash in the
 * group, like the voucher hash chain. Who may read it is decided by
 * GroupAccess, never by this row.
 *
 * @property int $id
 * @property int $corporate_group_id
 * @property int $number
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property CarbonImmutable $entry_date
 * @property GroupEntryKind $kind
 * @property EliminationType $type
 * @property GroupEntryOrigin $origin
 * @property ?int $reverses_id
 * @property ?string $suggestion_key
 * @property ?string $reason
 * @property ?array<string, mixed> $source
 * @property int $rows_count
 * @property int $total_ore
 * @property int $accepted_by
 * @property CarbonImmutable $accepted_at
 * @property ?string $previous_hash
 * @property string $hash
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, GroupEntryRow> $rows
 * @property-read ?GroupEntry $reversedBy
 * @property-read ?User $acceptor
 */
class GroupEntry extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'corporate_group_id', 'number', 'period_from', 'period_to', 'entry_date', 'kind', 'type', 'origin', 'reverses_id',
        'suggestion_key', 'reason', 'source', 'rows_count', 'total_ore', 'accepted_by', 'accepted_at', 'previous_hash', 'hash',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A group ledger entry cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A group ledger entry cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'corporate_group_id' => 'integer',
            'number' => 'integer',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'entry_date' => 'immutable_date',
            'kind' => GroupEntryKind::class,
            'type' => EliminationType::class,
            'origin' => GroupEntryOrigin::class,
            'reverses_id' => 'integer',
            'source' => 'array',
            'rows_count' => 'integer',
            'total_ore' => 'integer',
            'accepted_by' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<GroupEntryRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(GroupEntryRow::class)->orderBy('id');
    }

    /** @return HasOne<GroupEntry, $this> */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    /** @return BelongsTo<GroupEntry, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** @return BelongsTo<CorporateGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CorporateGroup::class, 'corporate_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * What the hash covers, as written: everything but the id, the hash
     * itself and the insert time. The acceptance time keeps its offset.
     *
     * @param  list<array{company_id: int, account_number: ?int, group_account_number: int, debit_ore: int, credit_ore: int}>  $rows
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function content(array $entry, array $rows): array
    {
        return [
            'corporate_group_id' => (int) $entry['corporate_group_id'],
            'number' => (int) $entry['number'],
            'period_from' => (string) $entry['period_from'],
            'period_to' => (string) $entry['period_to'],
            'entry_date' => (string) $entry['entry_date'],
            'kind' => (string) $entry['kind'],
            'type' => (string) $entry['type'],
            'origin' => (string) $entry['origin'],
            'reverses_id' => $entry['reverses_id'] === null ? null : (int) $entry['reverses_id'],
            'suggestion_key' => $entry['suggestion_key'],
            'reason' => $entry['reason'],
            'source' => $entry['source'],
            'rows_count' => (int) $entry['rows_count'],
            'total_ore' => (int) $entry['total_ore'],
            'accepted_by' => (int) $entry['accepted_by'],
            'accepted_at' => (string) $entry['accepted_at'],
            'rows' => array_map(fn (array $row): array => [
                (int) $row['company_id'],
                $row['account_number'] === null ? null : (int) $row['account_number'],
                (int) $row['group_account_number'],
                (int) $row['debit_ore'],
                (int) $row['credit_ore'],
            ], $rows),
        ];
    }

    /**
     * SHA-256 of the previous hash followed by the canonical JSON of the content.
     *
     * @param  array<string, mixed>  $content
     */
    public static function chainHash(?string $previous, array $content): string
    {
        return hash('sha256', ($previous ?? '').Consolidation::canonicalJson($content));
    }

    /** The hash recomputed from what is stored now (needs the rows). */
    public function recomputedHash(): string
    {
        return self::chainHash($this->previous_hash, self::content([
            ...$this->getAttributes(),
            'period_from' => $this->period_from->toDateString(),
            'period_to' => $this->period_to->toDateString(),
            'entry_date' => $this->entry_date->toDateString(),
            'kind' => $this->kind->value,
            'type' => $this->type->value,
            'origin' => $this->origin->value,
            'source' => $this->source,
            'accepted_at' => $this->accepted_at->format('Y-m-d H:i:sP'),
        ], array_values($this->rows->map(fn (GroupEntryRow $row): array => [
            'company_id' => $row->company_id,
            'account_number' => $row->account_number,
            'group_account_number' => $row->group_account_number,
            'debit_ore' => $row->debit_ore,
            'credit_ore' => $row->credit_ore,
        ])->all())));
    }
}
