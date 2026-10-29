<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry in the behandlingshistorik (AGENTS.md §6.6). Append-only: the model
 * refuses updates and deletes, and database triggers back that up.
 *
 * Not company-scoped through BelongsToCompany on purpose: some entries belong
 * to no company (signing in), and recording must never fail because no company
 * is known. Read entries with forCompany(). See ADR-006.
 *
 * @property ?int $company_id
 * @property ?int $user_id
 * @property string $action
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property array<string, mixed> $payload
 */
class AuditLog extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'payload',
        'ip_address',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit log entries cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit log entries cannot be deleted.');
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCompany(Builder $query, Company $company): Builder
    {
        return $query->where('company_id', $company->id);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
