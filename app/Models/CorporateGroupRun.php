<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Group\Consolidation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One stored consolidation of a shared group (ADR-060, addendum 2026-11-07,
 * 2.4; ADR-081 E24): the period, who ran it, what it read (inputs, with
 * their hash) and the consolidated figures it gave (output, with their
 * hash). The same inputs give the same output. Spans companies like
 * CorporateGroup, holds consolidated figures only, never one company's, and
 * is append-only (a trigger refuses every change and deletion). Who may read
 * it is decided by GroupAccess, never by this row.
 *
 * @property int $id
 * @property int $corporate_group_id
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property int $run_by
 * @property array<string, mixed> $inputs
 * @property string $inputs_hash
 * @property array<string, mixed> $output
 * @property string $output_hash
 * @property bool $preliminary
 * @property CarbonImmutable $created_at
 * @property-read ?User $runner
 */
class CorporateGroupRun extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['corporate_group_id', 'period_from', 'period_to', 'run_by', 'inputs', 'inputs_hash', 'output', 'output_hash', 'preliminary'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A consolidation run cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A consolidation run cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'corporate_group_id' => 'integer',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'run_by' => 'integer',
            'inputs' => 'array',
            'output' => 'array',
            'preliminary' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CorporateGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CorporateGroup::class, 'corporate_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    /** Whether the stored inputs and output still give the stored hashes (nothing was altered in the database). */
    public function intact(): bool
    {
        return hash_equals($this->inputs_hash, Consolidation::hash($this->inputs))
            && hash_equals($this->output_hash, Consolidation::hash($this->output));
    }
}
