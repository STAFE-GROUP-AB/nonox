<?php

declare(strict_types=1);

use App\Actions\Demo\PurgeExpiredDemos;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

/*
 * The only deletion NONOX makes (ADR-077): demo companies whose time has run
 * out, with their guests. These tests are the proof that it reaches nothing
 * else, and that the immutability triggers still hold for everyone else.
 */

/**
 * A demo company with one posted voucher, owned by a guest.
 *
 * @return array{0: User, 1: Company}
 */
function expiredDemo(string $expires = '-1 hour'): array
{
    [$owner, $company] = Bookkeeping::company();
    Bookkeeping::post($owner, $company);

    $at = now()->modify($expires);
    $company->forceFill(['is_test_company' => true, 'demo_expires_at' => $at])->save();
    $owner->forceFill(['demo_expires_at' => $at])->save();

    return [$owner, $company];
}

function vouchersOf(int $companyId): int
{
    return CompanyScope::bypass(fn (): int => Voucher::query()->where('company_id', $companyId)->count());
}

it('removes an expired demo company and its guest, with the posted vouchers', function () {
    [$guest, $demo] = expiredDemo();
    [, $real] = Bookkeeping::company();
    Bookkeeping::post(User::query()->find($real->user_id), $real);

    $result = app(PurgeExpiredDemos::class)();

    expect($result['companies'])->toBe(1)
        ->and($result['users'])->toBe(1)
        ->and(Company::query()->whereKey($demo->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($guest->id)->exists())->toBeFalse()
        ->and(vouchersOf($demo->id))->toBe(0)
        ->and(Company::query()->whereKey($real->id)->exists())->toBeTrue()
        ->and(vouchersOf($real->id))->toBe(1);
});

it('leaves a demo whose time has not run out', function () {
    [$guest, $demo] = expiredDemo('+1 hour');

    $result = app(PurgeExpiredDemos::class)();

    expect($result['companies'])->toBe(0)
        ->and(Company::query()->whereKey($demo->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($guest->id)->exists())->toBeTrue()
        ->and(vouchersOf($demo->id))->toBe(1);
});

it('never removes a company that is not flagged, however old', function () {
    [, $real] = Bookkeeping::company();
    Bookkeeping::post(User::query()->find($real->user_id), $real);

    $result = app(PurgeExpiredDemos::class)();

    expect($result['companies'])->toBe(0)
        ->and(Company::query()->whereKey($real->id)->exists())->toBeTrue()
        ->and(vouchersOf($real->id))->toBe(1);
});

it('leaves a flagged company that has a real member', function () {
    [, $demo] = expiredDemo();
    $real = User::factory()->create();
    $demo->users()->attach($real, ['role' => 'admin']);

    $result = app(PurgeExpiredDemos::class)();

    expect($result['companies'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and(Company::query()->whereKey($demo->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($real->id)->exists())->toBeTrue();
});

it('leaves a company flagged as expired that is not a test company', function () {
    [, $demo] = expiredDemo();
    $demo->forceFill(['is_test_company' => false])->save();

    expect(app(PurgeExpiredDemos::class)()['companies'])->toBe(0)
        ->and(Company::query()->whereKey($demo->id)->exists())->toBeTrue();
});

it('removes expired guests that belong to nothing, and only them', function () {
    $orphan = User::factory()->create(['demo_expires_at' => now()->subMinute()]);
    $future = User::factory()->create(['demo_expires_at' => now()->addHour()]);
    $real = User::factory()->create();

    $result = app(PurgeExpiredDemos::class)();

    expect($result['users'])->toBe(1)
        ->and(User::query()->whereKey($orphan->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($future->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($real->id)->exists())->toBeTrue();
});

it('lets the database refuse every other deletion, with or without the purge setting', function () {
    [, $demo] = expiredDemo();
    [$owner, $real] = Bookkeeping::company();
    $voucher = Bookkeeping::post($owner, $real);

    $attempt = function (callable $statement): bool {
        try {
            DB::transaction($statement);

            return true;
        } catch (Throwable) {
            return false;
        }
    };

    // A posted voucher of the demo cannot be deleted without the setting (§1.2 holds).
    expect($attempt(fn () => DB::table('vouchers')->where('company_id', $demo->id)->delete()))->toBeFalse();

    // Naming the demo does not open a real company's rows, nor its company row.
    expect($attempt(function () use ($demo, $voucher) {
        DB::select("select set_config('nonox.demo_purge', ?, true)", [(string) $demo->id]);
        DB::table('voucher_rows')->where('voucher_id', $voucher->id)->delete();
        DB::table('vouchers')->where('id', $voucher->id)->delete();
    }))->toBeFalse();

    // Naming the real company opens nothing: it is not a demo.
    expect($attempt(function () use ($real) {
        DB::select("select set_config('nonox.demo_purge', ?, true)", [(string) $real->id]);
        DB::table('voucher_rows')->where('company_id', $real->id)->delete();
        DB::table('vouchers')->where('company_id', $real->id)->delete();
    }))->toBeFalse();

    expect($attempt(function () use ($real) {
        DB::select("select set_config('nonox.demo_purge', ?, true)", [(string) $real->id]);
        DB::table('teams')->where('id', $real->id)->delete();
    }))->toBeFalse();

    // The company row of a real company cannot be deleted at all.
    expect($attempt(fn () => DB::table('teams')->where('id', $real->id)->delete()))->toBeFalse()
        ->and(vouchersOf($real->id))->toBe(1)
        ->and(vouchersOf($demo->id))->toBe(1);
});

it('does not let a demo that has not expired be deleted, even with the setting', function () {
    [, $demo] = expiredDemo('+1 hour');

    $deleted = true;

    try {
        DB::transaction(function () use ($demo) {
            DB::select("select set_config('nonox.demo_purge', ?, true)", [(string) $demo->id]);
            DB::table('teams')->where('id', $demo->id)->delete();
        });
    } catch (Throwable) {
        $deleted = false;
    }

    expect($deleted)->toBeFalse()->and(Company::query()->whereKey($demo->id)->exists())->toBeTrue();
});

it('keeps updates of posted vouchers forbidden, also for a demo', function () {
    [, $demo] = expiredDemo();

    $changed = true;

    try {
        DB::transaction(function () use ($demo) {
            DB::select("select set_config('nonox.demo_purge', ?, true)", [(string) $demo->id]);
            DB::table('vouchers')->where('company_id', $demo->id)->update(['description' => 'Ändrad']);
        });
    } catch (Throwable) {
        $changed = false;
    }

    expect($changed)->toBeFalse();
});
