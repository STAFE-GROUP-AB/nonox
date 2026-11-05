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

    /**
     * A posted voucher with the rows given as account => signed öre (debit positive).
     *
     * @param  array<int, int>  $rows
     */
    public static function postRows(User $user, Company $company, string $date, array $rows, string $series = 'A'): Voucher
    {
        return app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, [
            'voucher_date' => $date,
            'description' => 'Test',
            'series' => $series,
            'rows' => collect($rows)->map(fn (int $amount, int $account): array => ['account' => $account, 'debit_ore' => max($amount, 0), 'credit_ore' => max(-$amount, 0)])->values()->all(),
        ]));
    }

    /**
     * The files of an SRU ZIP.
     *
     * @return array{'INFO.SRU': string, 'BLANKETTER.SRU': string}
     */
    public static function unzipSru(string $content): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'srutest');
        file_put_contents($path, $content);
        $zip = new \ZipArchive;
        $zip->open($path);
        $files = ['INFO.SRU' => (string) $zip->getFromName('INFO.SRU'), 'BLANKETTER.SRU' => (string) $zip->getFromName('BLANKETTER.SRU')];
        $zip->close();
        unlink($path);

        return $files;
    }
}
