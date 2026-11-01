<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Models\BankFeedTransaction;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;

/**
 * NONOX Banking's part of "Att göra" on Översikt: bank connections whose
 * consent runs out soon or has run out, and fetched transactions not
 * matched to the ledger yet. Two queries.
 */
final class BankingTodos implements TodoProvider
{
    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        $items = [];

        if ($user->hasTeamPermission($company, 'banking:manage')) {
            $days = (int) BankingModule::setting(app(ModuleManager::class)->settings(BankingModule::KEY, $company), 'reconsent_reminder_days');
            $connections = BankConnection::query()
                ->where(fn ($query) => $query
                    ->where('status', ConnectionStatus::Expired->value)
                    ->orWhere(fn ($query) => $query->where('status', ConnectionStatus::Active->value)->where('valid_until', '<=', $today->addDays($days)->endOfDay())))
                ->orderBy('valid_until')
                ->get(['id', 'institution_name', 'status', 'valid_until']);

            foreach ($connections as $connection) {
                $expired = $connection->status === ConnectionStatus::Expired;
                $items[] = new TodoItem(
                    "banking-consent-{$connection->id}",
                    'bank',
                    $expired
                        ? __('Koppla :bank igen', ['bank' => $connection->institution_name])
                        : __('Förnya bankkopplingen till :bank', ['bank' => $connection->institution_name]),
                    $expired
                        ? __('Medgivandet har gått ut. Inga transaktioner hämtas förrän banken är kopplad igen.')
                        : __('Medgivandet gäller till och med :date. Förnya med BankID.', ['date' => $connection->valid_until?->toDateString()]),
                    route('banking.index'),
                    TodoItem::PRIORITY_BANK,
                    $expired ? null : $connection->valid_until?->startOfDay(),
                );
            }
        }

        if ($user->hasTeamPermission($company, 'banking:view') && $user->hasTeamPermission($company, 'vouchers:post')) {
            $unmatched = BankFeedTransaction::query()->whereNull('voucher_row_id')
                ->whereHas('account', fn ($query) => $query->where('is_active', true)->whereNotNull('account_id'))
                ->count();

            if ($unmatched > 0) {
                $items[] = new TodoItem(
                    'banking-unmatched',
                    'bank',
                    $unmatched === 1 ? __('1 banktransaktion att matcha') : __(':count banktransaktioner att matcha', ['count' => $unmatched]),
                    __('Hämtade från banken men inte kopplade till en rad i bokföringen.'),
                    route('banking.index'),
                    TodoItem::PRIORITY_BANK + 1,
                );
            }
        }

        return $items;
    }

    public function invalidatedBy(): array
    {
        return [BankConnection::class, BankFeedTransaction::class];
    }
}
