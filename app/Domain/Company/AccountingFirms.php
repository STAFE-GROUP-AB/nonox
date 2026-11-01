<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Models\ClientProfile;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Which accounting firms a client company may link (ADR-030): companies
 * marked as a firm, not archived, where a person who has the
 * Redovisningskonsult role in the client also owns or belongs to the firm.
 * That shared consultant is what connects the two; without one the firm is
 * not offered and cannot be linked, so a firm's name is never shown to a
 * company it has no one in.
 */
final class AccountingFirms
{
    /** @return Collection<int, Company> */
    public static function eligibleFor(Company $client): Collection
    {
        return self::query($client)->orderBy('name')->get(['id', 'name', 'org_number']);
    }

    public static function isEligible(Company $client, int $firmId): bool
    {
        return self::query($client)->whereKey($firmId)->exists();
    }

    /**
     * Links that touch the company, as the client or as the firm, where no
     * person with the Redovisningskonsult role in the client is still in the
     * firm. They are removed when a membership changes (ReconcileFirmLinks).
     *
     * @return Collection<int, Company> the client companies
     */
    public static function linksWithoutConsultant(Company $company): Collection
    {
        return Company::query()
            ->whereNotNull('accounting_firm_id')
            ->where(fn (Builder $touching) => $touching->whereKey($company->id)->orWhere('accounting_firm_id', $company->id))
            ->whereNotExists(fn (QueryBuilder $consultant) => $consultant->selectRaw('1')
                ->from('team_user as client_member')
                ->whereColumn('client_member.team_id', 'teams.id')
                ->where('client_member.role', ClientProfile::FIRM_ROLE)
                ->where(fn (QueryBuilder $inFirm) => $inFirm
                    ->whereExists(fn (QueryBuilder $owner) => $owner->selectRaw('1')
                        ->from('teams as firm')
                        ->whereColumn('firm.id', 'teams.accounting_firm_id')
                        ->whereColumn('firm.user_id', 'client_member.user_id'))
                    ->orWhereExists(fn (QueryBuilder $firmMember) => $firmMember->selectRaw('1')
                        ->from('team_user as firm_member')
                        ->whereColumn('firm_member.team_id', 'teams.accounting_firm_id')
                        ->whereColumn('firm_member.user_id', 'client_member.user_id'))))
            ->get();
    }

    /** @return Builder<Company> */
    private static function query(Company $client): Builder
    {
        return Company::query()
            ->where('is_accounting_firm', true)
            ->whereNull('archived_at')
            ->whereKeyNot($client->id)
            ->whereExists(fn (QueryBuilder $consultant) => $consultant->selectRaw('1')
                ->from('team_user as client_member')
                ->where('client_member.team_id', $client->id)
                ->where('client_member.role', ClientProfile::FIRM_ROLE)
                ->where(fn (QueryBuilder $inFirm) => $inFirm
                    ->whereColumn('client_member.user_id', 'teams.user_id')
                    ->orWhereExists(fn (QueryBuilder $firmMember) => $firmMember->selectRaw('1')
                        ->from('team_user as firm_member')
                        ->whereColumn('firm_member.team_id', 'teams.id')
                        ->whereColumn('firm_member.user_id', 'client_member.user_id'))));
    }
}
