<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Models\ClientProfile;
use App\Models\Company;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which brand a company's people see (ADR-030).
 *
 * A client company that has linked an accounting firm, while the firm is
 * still a firm, is not archived and has "Visa byråns varumärke för våra
 * kunder" on, is shown in the firm's logo and colours to everyone on the
 * client side: the owner, the other members and the employees on Min sida.
 * Members with the Redovisningskonsult role are the firm (ADR-028 §10) and
 * keep the company's own brand, as before.
 *
 * The firm's brand is cached per firm (one entry, forgotten when the firm
 * changes), so resolving it costs no query on a normal request.
 */
final class FirmBranding
{
    private const int TTL_SECONDS = 86400;

    /** The fields of a company that its clients' brand is made of. */
    private const array FIRM_FIELDS = ['name', 'brand_color', 'brand_accent_color', 'logo_path', 'is_accounting_firm', 'firm_brand_for_clients', 'archived_at'];

    /** The brand the company's client side sees. */
    public static function clientFacing(Company $company): PresentedBrand
    {
        $firm = $company->accounting_firm_id === null ? null : self::firms([$company->accounting_firm_id])[$company->accounting_firm_id] ?? null;

        return self::present($company, $firm);
    }

    /** The brand a member sees in the company: the firm's on the client side, the company's own for the firm's consultants. */
    public static function forViewer(?User $user, Company $company): PresentedBrand
    {
        if ($user instanceof User && self::isFirmSide($user, $company)) {
            return PresentedBrand::own($company);
        }

        return self::clientFacing($company);
    }

    /**
     * The firm's brand for an email to the user about their current company,
     * or null when no firm brand applies (the email then looks as before).
     */
    public static function forUser(User $user): ?PresentedBrand
    {
        $company = $user->currentCompany();

        return $company instanceof Company ? self::emailBrand($user, $company) : null;
    }

    /** The firm's brand for an email to the user about the company, or null when none applies. */
    public static function emailBrand(User $user, Company $company): ?PresentedBrand
    {
        $brand = self::forViewer($user, $company);

        return $brand->viaFirm() ? $brand : null;
    }

    /** The firm's brand for someone invited to a client company, unless they are invited as the firm's consultant. */
    public static function forInvitation(TeamInvitation $invitation): ?PresentedBrand
    {
        $company = $invitation->team;

        if (! $company instanceof Company || $invitation->role === ClientProfile::FIRM_ROLE) {
            return null;
        }

        $brand = self::clientFacing($company);

        return $brand->viaFirm() ? $brand : null;
    }

    /**
     * For an email that spans several companies (the task digest): the
     * firm's brand when every company in it shows the user the same firm,
     * else none. Two queries for any number of users and companies.
     *
     * @param  list<array{user_id: int, company_id: int, company_name: string, firm_id: ?int}>  $rows
     * @return array<int, PresentedBrand> by user id, only users with a shared firm
     */
    public static function sharedFirmBrands(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        // Where the user is the firm's consultant, the company is not shown to them in the firm's brand.
        $firmSide = DB::table('team_user')
            ->whereIn('user_id', array_unique(array_column($rows, 'user_id')))
            ->whereIn('team_id', array_unique(array_column($rows, 'company_id')))
            ->where('role', ClientProfile::FIRM_ROLE)
            ->get(['user_id', 'team_id'])
            ->map(fn (object $row): string => "{$row->user_id}:{$row->team_id}")
            ->flip();

        $perUser = [];

        foreach ($rows as $row) {
            $firm = $firmSide->has("{$row['user_id']}:{$row['company_id']}") ? null : $row['firm_id'];
            $perUser[$row['user_id']]['firms'][] = $firm;
            $perUser[$row['user_id']]['companies'][$row['company_id']] = $row['company_name'];
        }

        $shared = array_filter(array_map(function (array $user): ?int {
            $firms = array_unique($user['firms']);

            return count($firms) === 1 && is_int($firms[0]) ? $firms[0] : null;
        }, $perUser));

        $firms = self::firms(array_values($shared));
        $brands = [];

        foreach ($shared as $userId => $firmId) {
            $firm = $firms[$firmId] ?? null;

            if ($firm === null) {
                continue;
            }

            $companies = $perUser[$userId]['companies'];
            $brands[$userId] = new PresentedBrand((int) array_key_first($companies), implode(', ', $companies), $firm['color'], $firm['accent'], $firm['logo_path'], $firm['id'], $firm['name']);
        }

        return $brands;
    }

    /** A member with the Redovisningskonsult role is the firm (ADR-028 §10); the owner never is. */
    public static function isFirmSide(User $user, Company $company): bool
    {
        if ($user->ownsTeam($company)) {
            return false;
        }

        // The memberships are loaded with the user's companies on every page (currentCompany), so this costs no query.
        $membership = $user->teams->firstWhere('id', $company->id)?->getRelation('membership');

        return $membership?->getAttribute('role') === ClientProfile::FIRM_ROLE;
    }

    /**
     * The usable firms among the ids, each as plain data, or absent when the
     * company is not (or no longer) a firm showing its brand. One cache read,
     * and one query for the firms not cached yet.
     *
     * @param  list<int>  $firmIds
     * @return array<int, array{id: int, name: string, color: ?string, accent: ?string, logo_path: ?string}>
     */
    public static function firms(array $firmIds): array
    {
        $firmIds = array_values(array_unique($firmIds));

        if ($firmIds === []) {
            return [];
        }

        $keys = array_map(self::key(...), $firmIds);
        $cached = Cache::many($keys);
        $missing = array_values(array_filter($firmIds, fn (int $id): bool => ! is_array($cached[self::key($id)] ?? null)));

        if ($missing !== []) {
            $found = Company::query()
                ->whereKey($missing)
                ->where('is_accounting_firm', true)
                ->where('firm_brand_for_clients', true)
                ->whereNull('archived_at')
                ->get(['id', 'name', 'brand_color', 'brand_accent_color', 'logo_path'])
                ->keyBy('id');

            $fresh = [];

            foreach ($missing as $id) {
                $firm = $found->get($id);
                $fresh[self::key($id)] = ['firm' => $firm instanceof Company ? [
                    'id' => $firm->id,
                    'name' => (string) $firm->name,
                    'color' => $firm->brand_color,
                    'accent' => $firm->brand_accent_color,
                    'logo_path' => $firm->logo_path,
                ] : null];
            }

            Cache::putMany($fresh, self::TTL_SECONDS);
            $cached = [...$cached, ...$fresh];
        }

        $firms = [];

        foreach ($firmIds as $id) {
            $firm = self::fromCache($cached[self::key($id)]['firm'] ?? null);

            if ($firm !== null) {
                $firms[$id] = $firm;
            }
        }

        return $firms;
    }

    /** @return array{id: int, name: string, color: ?string, accent: ?string, logo_path: ?string}|null */
    private static function fromCache(mixed $firm): ?array
    {
        if (! is_array($firm) || ! is_int($firm['id'] ?? null) || ! is_string($firm['name'] ?? null)) {
            return null;
        }

        $text = fn (mixed $value): ?string => is_string($value) ? $value : null;

        return [
            'id' => $firm['id'],
            'name' => $firm['name'],
            'color' => $text($firm['color'] ?? null),
            'accent' => $text($firm['accent'] ?? null),
            'logo_path' => $text($firm['logo_path'] ?? null),
        ];
    }

    /**
     * @param  array{id: int, name: string, color: ?string, accent: ?string, logo_path: ?string}|null  $firm
     */
    public static function present(Company $company, ?array $firm): PresentedBrand
    {
        if ($firm === null) {
            return PresentedBrand::own($company);
        }

        return new PresentedBrand($company->id, (string) $company->name, $firm['color'], $firm['accent'], $firm['logo_path'], $firm['id'], $firm['name']);
    }

    /** Called when a company is saved: a change to what its clients see forgets its cached brand. */
    public static function forgetIfChanged(Company $company): void
    {
        if (! $company->wasChanged(self::FIRM_FIELDS)) {
            return;
        }

        Cache::forget(self::key($company->id));
    }

    private static function key(int $firmId): string
    {
        return "brand:firm:{$firmId}";
    }
}
