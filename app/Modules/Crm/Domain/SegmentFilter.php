<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A segment's filters (ADR-048), over the accounts and the people's place
 * at them. Every filter that is set must hold (AND); within one filter any
 * of the values is enough (OR). A person is in the segment when they are
 * at one account that passes the account filters in a role that passes
 * the role filter, and their own legal basis passes.
 *
 * - statuses: AccountStatus values
 * - kinds: AccountKind values
 * - tags: any of these tags on the account
 * - owner_id: the account's responsible seller
 * - sni: the account's SNI code starts with this
 * - city: the account's city, case-insensitive, exact
 * - roles: PersonRole values at that account
 * - legal_bases: LegalBasis values of the person
 */
final readonly class SegmentFilter
{
    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $kinds
     * @param  list<string>  $tags
     * @param  list<string>  $roles
     * @param  list<string>  $legalBases
     */
    public function __construct(
        public array $statuses = [],
        public array $kinds = [],
        public array $tags = [],
        public ?int $ownerId = null,
        public string $sni = '',
        public string $city = '',
        public array $roles = [],
        public array $legalBases = [],
    ) {}

    /** @param  array<string, mixed>  $stored */
    public static function from(array $stored): self
    {
        $list = fn (string $key, array $allowed): array => array_values(array_intersect(array_map('strval', (array) ($stored[$key] ?? [])), $allowed));

        return new self(
            statuses: $list('statuses', array_column(AccountStatus::cases(), 'value')),
            kinds: $list('kinds', array_column(AccountKind::cases(), 'value')),
            tags: array_values(array_unique(array_filter(array_map(fn (mixed $tag): string => mb_strtolower(trim((string) $tag)), (array) ($stored['tags'] ?? []))))),
            ownerId: is_numeric($stored['owner_id'] ?? null) ? (int) $stored['owner_id'] : null,
            sni: (string) preg_replace('/\D/', '', (string) ($stored['sni'] ?? '')),
            city: mb_substr(trim((string) ($stored['city'] ?? '')), 0, 100),
            roles: $list('roles', array_column(PersonRole::cases(), 'value')),
            legalBases: $list('legal_bases', array_column(LegalBasis::cases(), 'value')),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'statuses' => $this->statuses,
            'kinds' => $this->kinds,
            'tags' => $this->tags,
            'owner_id' => $this->ownerId,
            'sni' => $this->sni,
            'city' => $this->city,
            'roles' => $this->roles,
            'legal_bases' => $this->legalBases,
        ];
    }

    /**
     * Narrows a query over crm_accounts (joined with crm_account_person) to
     * the accounts that pass the account filters, and the role filter on
     * the link.
     */
    public function constrainAccounts(QueryBuilder $query): void
    {
        $query
            ->when($this->statuses !== [], fn ($q) => $q->whereIn('crm_accounts.status', $this->statuses))
            ->when($this->kinds !== [], fn ($q) => $q->whereIn('crm_accounts.kind', $this->kinds))
            ->when($this->tags !== [], fn ($q) => $q->where(function ($inner): void {
                foreach ($this->tags as $tag) {
                    $inner->orWhereJsonContains('crm_accounts.tags', $tag);
                }
            }))
            ->when($this->ownerId !== null, fn ($q) => $q->where('crm_accounts.owner_id', $this->ownerId))
            ->when($this->sni !== '', fn ($q) => $q->where('crm_accounts.sni_code', 'like', addcslashes($this->sni, '%_').'%'))
            ->when($this->city !== '', fn ($q) => $q->whereRaw('lower(crm_accounts.city) = ?', [mb_strtolower($this->city)]))
            ->when($this->roles !== [], fn ($q) => $q->whereIn('crm_account_person.role', $this->roles));
    }

    /**
     * One row per person and segment account, with what MarketingEligibility
     * reads (MarketingCandidates). Plain rows, not models: a segment can
     * hold thousands of people, and the composer counts them on every
     * render with this one query.
     */
    public function rows(): QueryBuilder
    {
        $query = MarketingCandidates::base();
        $this->constrainAccounts($query);

        return $query->when($this->legalBases !== [], fn ($q) => $q->whereIn('crm_people.legal_basis', $this->legalBases));
    }
}
