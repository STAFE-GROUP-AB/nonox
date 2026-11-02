<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Waitlist\WaitlistStatus;
use App\Notifications\NewWaitlistEntry;
use Carbon\CarbonImmutable;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Someone who wants to use the hosted service during the open beta
 * (ADR-038). Instance data, not company data, so no BelongsToCompany: an
 * entry exists before any company does, and only /hq reads it.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ?string $phone
 * @property string $company_name
 * @property ?string $org_number
 * @property string $industry
 * @property string $employees
 * @property string $current_system
 * @property ?string $wants_to_test
 * @property ?string $why_switch
 * @property CarbonImmutable $consent_at
 * @property WaitlistStatus $status
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?string $decision_note
 * @property ?int $approved_user_id
 * @property ?string $ip_hash
 * @property ?string $user_agent
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'company_name',
        'org_number',
        'industry',
        'employees',
        'current_system',
        'wants_to_test',
        'why_switch',
        'consent_at',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'approved_user_id',
        'ip_hash',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => WaitlistStatus::class,
            'consent_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_user_id');
    }

    /**
     * Removes the HQ notices about these entries, which carry the company
     * name: they go when the entries go.
     *
     * @param  array<int, int>  $ids
     */
    public static function forgetNotices(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::table('notifications')
            ->where('type', NewWaitlistEntry::class)
            ->whereIn(DB::raw("(data::jsonb->>'waitlist_entry_id')"), array_map('strval', $ids))
            ->delete();
    }

    /** The salted hash the entry keeps instead of the IP address. */
    public static function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, 'waitlist-ip|'.config('app.key'));
    }
}
