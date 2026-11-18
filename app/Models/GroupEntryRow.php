<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of a group ledger entry (ADR-060, addendum 2026-11-07, 2.4): the
 * member company whose amount it eliminates, that company's own account
 * (empty for a manual row), the group account, and a debit or a credit.
 * Not company-scoped: the group ledger spans the group's companies like the
 * run log, and is read only through GroupAccess. Append-only.
 *
 * @property int $id
 * @property int $group_entry_id
 * @property int $company_id
 * @property ?int $account_number
 * @property int $group_account_number
 * @property int $debit_ore
 * @property int $credit_ore
 */
class GroupEntryRow extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['group_entry_id', 'company_id', 'account_number', 'group_account_number', 'debit_ore', 'credit_ore'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A group ledger row cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A group ledger row cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'group_entry_id' => 'integer',
            'company_id' => 'integer',
            'account_number' => 'integer',
            'group_account_number' => 'integer',
            'debit_ore' => 'integer',
            'credit_ore' => 'integer',
        ];
    }

    /** @return BelongsTo<GroupEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(GroupEntry::class, 'group_entry_id');
    }
}
