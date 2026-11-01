<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\Actions\ConnectBank;
use App\Modules\Banking\Actions\MapBankAccount;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Domain\ProviderTransaction;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * NONOX Banking test setup with the fake bank. Nothing here reaches a real
 * bank or provider.
 */
final class Banking
{
    public static function enable(Company $company, User $owner): void
    {
        app(ModuleManager::class)->enable(BankingModule::KEY, $company, $owner);
    }

    /** Connects Demobanken through ConnectBank, as the callback would. */
    public static function connect(User $owner, Company $company, string $validUntil = '2026-09-18'): BankConnection
    {
        $connect = app(ConnectBank::class);
        ['flow' => $flow] = $connect->start($owner, $company, 'Demobanken', 'business', 'https://nonox.test/bank/klar');

        return $connect->complete($owner, $company, $flow, "fake-{$validUntil}-test");
    }

    /** The connection's company account (number 1), mapped to the ledger account. */
    public static function mapped(User $owner, Company $company, BankConnection $connection, int $number = 1930): BankFeedAccount
    {
        $account = CurrentCompany::run($company, fn () => BankFeedAccount::query()->where('bank_connection_id', $connection->id)->where('identification_hash', 'fake-ident-1')->firstOrFail());
        app(MapBankAccount::class)($owner, $company, $account, $number);

        return $account->fresh() ?? $account;
    }

    public static function transaction(string $date, int $ore, ?string $id = null, ?string $counterparty = null, ?string $reference = null, ?string $message = null): ProviderTransaction
    {
        $day = CarbonImmutable::parse($date);

        return new ProviderTransaction($id, null, $day, $day, $ore, 'SEK', $counterparty, $message, $reference);
    }
}
