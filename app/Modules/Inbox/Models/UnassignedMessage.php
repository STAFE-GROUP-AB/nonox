<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Models;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Mail to the shared address from a sender on more than one company's
 * allowlist. It belongs to no company and is never interpreted until a
 * member of one of the candidate companies claims it (ADR-023). Only the
 * sender, subject and date are shown before that.
 *
 * Not company-scoped on purpose: every read goes through visibleTo().
 *
 * @property string $id
 * @property string $recipient
 * @property string $from_email
 * @property ?string $from_name
 * @property ?string $subject
 * @property CarbonImmutable $received_at
 * @property string $eml_path
 * @property string $eml_sha256
 * @property int $eml_size
 * @property array<string, mixed> $transport
 * @property list<int> $candidate_company_ids
 * @property ?int $claimed_company_id
 * @property ?int $claimed_by
 * @property ?CarbonImmutable $claimed_at
 */
class UnassignedMessage extends Model
{
    use HasUuids;

    protected $table = 'inbox_unassigned_messages';

    /** @var list<string> */
    protected $fillable = ['recipient', 'from_email', 'from_name', 'subject', 'received_at', 'eml_path', 'eml_sha256', 'eml_size', 'transport', 'candidate_company_ids', 'claimed_company_id', 'claimed_by', 'claimed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'eml_size' => 'integer',
            'transport' => 'array',
            'candidate_company_ids' => 'array',
            'claimed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Unclaimed messages one of whose candidates is the company.
     *
     * @return Builder<self>
     */
    public static function waitingFor(Company $company): Builder
    {
        return self::query()
            ->whereNull('claimed_at')
            ->whereJsonContains('candidate_company_ids', $company->id);
    }

    public function isCandidate(Company $company): bool
    {
        return in_array($company->id, array_map('intval', $this->candidate_company_ids), true);
    }

    public function canBeClaimedBy(User $user, Company $company): bool
    {
        return $this->claimed_at === null
            && $this->isCandidate($company)
            && $user->belongsToTeam($company)
            && $user->hasTeamPermission($company, 'inbox:manage');
    }
}
