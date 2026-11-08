<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Models\Company;
use App\Models\User;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes demo companies whose time has run out, with their guests and
 * files (ADR-077). It is the only deletion NONOX makes, so it is narrow:
 *
 *  - a company is a candidate only if it is flagged as a demo AND as a test
 *    company AND its time has passed AND every person who belongs to it is a
 *    guest. One real member and it is left alone, however it got flagged;
 *  - the transaction names that one company in a database setting, which is
 *    what lets the immutability triggers let its rows go; the triggers
 *    check the company's flag again, and the trigger on teams refuses any
 *    company that is not an expired demo;
 *  - it never truncates, wipes or drops anything, and a candidate that fails
 *    stays for the next run.
 */
final class PurgeExpiredDemos
{
    /**
     * @return array{companies: int, users: int, skipped: int}
     */
    public function __invoke(): array
    {
        $companies = 0;
        $users = 0;
        $skipped = 0;

        $candidates = CompanyScope::bypass(fn () => Company::query()
            ->whereNotNull('demo_expires_at')
            ->where('demo_expires_at', '<', now())
            ->where('is_test_company', true)
            ->orderBy('id')
            ->pluck('id'));

        foreach ($candidates as $id) {
            $purged = $this->purge((int) $id);

            if ($purged === null) {
                $skipped++;

                continue;
            }

            $companies++;
            $users += $purged;
        }

        $users += $this->purgeLeftoverGuests();

        return ['companies' => $companies, 'users' => $users, 'skipped' => $skipped];
    }

    /** @return ?int the number of guests removed with it, or null when the company is not a demo to remove */
    private function purge(int $companyId): ?int
    {
        $company = CompanyScope::bypass(fn () => Company::query()->find($companyId));

        if (! $company instanceof Company || ! $this->isRemovable($company)) {
            return null;
        }

        $guestIds = $this->memberIds($company);

        return DB::transaction(function () use ($company, $guestIds): int {
            DB::select("select set_config('nonox.demo_purge', ?, true)", [(string) $company->id]);

            $paths = $this->filePaths($company->id);

            DB::table('audit_logs')->where('company_id', $company->id)->delete();
            DB::table('teams')->where('id', $company->id)->delete();

            $removed = 0;

            foreach ($guestIds as $guestId) {
                $removed += $this->deleteGuest($guestId);
            }

            StoredFiles::deleteUnreferenced($paths);

            return $removed;
        });
    }

    private function isRemovable(Company $company): bool
    {
        if ($company->demo_expires_at === null || $company->demo_expires_at->isFuture() || ! $company->isTestCompany()) {
            return false;
        }

        $ids = $this->memberIds($company);

        return $ids !== []
            && User::query()->whereIn('id', $ids)->whereNull('demo_expires_at')->doesntExist();
    }

    /**
     * The owner and every member of the company.
     *
     * @return list<int>
     */
    private function memberIds(Company $company): array
    {
        $ids = DB::table('team_user')->where('team_id', $company->id)->pluck('user_id')->all();
        $ids[] = $company->user_id;

        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    /** @return list<string> every stored file the company's rows refer to */
    private function filePaths(int $companyId): array
    {
        $paths = [];

        foreach (StoredFiles::REFERENCES as $table => $column) {
            if (! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            foreach (DB::table($table)->where('company_id', $companyId)->whereNotNull($column)->pluck($column) as $path) {
                $paths[] = (string) $path;
            }
        }

        return $paths;
    }

    /** One expired guest, and what hangs on it; false when it belongs to a company that is still there. */
    private function deleteGuest(int $userId): int
    {
        $guest = User::query()->whereKey($userId)->whereNotNull('demo_expires_at')->where('demo_expires_at', '<', now())->first();

        if (! $guest instanceof User || DB::table('team_user')->where('user_id', $userId)->exists() || DB::table('teams')->where('user_id', $userId)->exists()) {
            return 0;
        }

        DB::select("select set_config('nonox.demo_purge_user', ?, true)", [(string) $userId]);

        DB::table('audit_logs')->whereNull('company_id')->where('user_id', $userId)->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $userId)->delete();
        }

        $guest->delete();

        return 1;
    }

    /** Guests whose time has passed and who belong to nothing: left over from an interrupted run. */
    private function purgeLeftoverGuests(): int
    {
        $removed = 0;

        $ids = User::query()->whereNotNull('demo_expires_at')->where('demo_expires_at', '<', now())->pluck('id');

        foreach ($ids as $id) {
            $removed += DB::transaction(fn (): int => $this->deleteGuest((int) $id));
        }

        return $removed;
    }
}
