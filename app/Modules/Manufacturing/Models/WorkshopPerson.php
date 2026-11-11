<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\StaffLedger\Domain\Pin;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone who reports production time on the workshop terminal (ADR-071
 * §7). Not a NONOX user and not a person in the staff ledger: a name, an
 * optional photo (only with consent, removable) and a personal code kept
 * as a salt and a PBKDF2 verifier. No personal number. Archiving takes the
 * person off the terminal; their reported time stays until the retention
 * period has passed.
 *
 * TODO(legal-verify): the photo consent and the register extract
 * (DataSubjectExport::workshopPerson) for people who are not NONOX users.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property ?string $photo_path
 * @property ?CarbonImmutable $photo_consent_at
 * @property ?string $photo_consent_version
 * @property ?string $code_salt
 * @property ?string $code_verifier
 * @property int $failed_attempts
 * @property int $lockouts
 * @property ?CarbonImmutable $last_lockout_at
 * @property ?CarbonImmutable $code_blocked_at
 * @property ?CarbonImmutable $locked_until
 * @property ?CarbonImmutable $archived_at
 * @property ?int $created_by
 */
class WorkshopPerson extends Model
{
    use BelongsToCompany;

    /** Wrong codes in a row before the person is locked out for a while. */
    public const int MAX_ATTEMPTS = 5;

    public const int LOCK_MINUTES = 5;

    /** Lock-outs within LOCKOUT_WINDOW_HOURS after which the person stays blocked until a manager sets a new code. */
    public const int ESCALATE_AFTER = 3;

    public const int LOCKOUT_WINDOW_HOURS = 24;

    /** @var list<string> */
    protected $hidden = ['code_salt', 'code_verifier'];

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'archived_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'photo_consent_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'last_lockout_at' => 'immutable_datetime',
            'code_blocked_at' => 'immutable_datetime',
            'lockouts' => 'integer',
            'archived_at' => 'immutable_datetime',
            'failed_attempts' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function hasCode(): bool
    {
        return $this->code_verifier !== null;
    }

    public function isLocked(): bool
    {
        return $this->isBlocked() || ($this->locked_until !== null && $this->locked_until->isFuture());
    }

    /** Blocked after repeated lock-outs: only a new code from a manager lifts it. */
    public function isBlocked(): bool
    {
        return $this->code_blocked_at !== null;
    }

    public function codeMatches(string $code): bool
    {
        return Pin::matches($code, $this->code_salt, $this->code_verifier);
    }
}
