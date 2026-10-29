<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;

/**
 * Readable helpers for bookkeeping tests.
 */
final class Bookkeeping
{
    /**
     * An owner with a company that has a chart of accounts, the 2026 fiscal
     * year and the default voucher series.
     *
     * @return array{0: User, 1: Company}
     */
    public static function company(): array
    {
        $owner = User::factory()->withCompany()->create();
        $company = $owner->currentCompany();

        app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse('2026-06-15'));

        return [$owner->fresh(), $company];
    }

    public static function member(Company $company, string $role): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user, ['role' => $role]);
        $user->switchCompany($company);

        return $user->fresh();
    }

    /**
     * A draft moving an amount from one account to another: debit $debit,
     * credit $credit.
     */
    public static function draft(User $user, Company $company, int $ore = 100000, int $debit = 1930, int $credit = 3001, string $date = '2026-03-01', string $series = 'A'): Voucher
    {
        return app(SaveDraftVoucher::class)($user, $company, [
            'voucher_date' => $date,
            'description' => 'Försäljning',
            'series' => $series,
            'rows' => [
                ['account' => $debit, 'debit_ore' => $ore],
                ['account' => $credit, 'credit_ore' => $ore],
            ],
        ]);
    }

    public static function post(User $user, Company $company, int $ore = 100000, int $debit = 1930, int $credit = 3001, string $date = '2026-03-01', string $series = 'A'): Voucher
    {
        return app(PostVoucher::class)($user, self::draft($user, $company, $ore, $debit, $credit, $date, $series));
    }
}
