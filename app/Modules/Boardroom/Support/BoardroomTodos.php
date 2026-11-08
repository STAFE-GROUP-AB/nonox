<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Support;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Boardroom\Domain\RegisterException;
use App\Modules\Boardroom\Domain\ShareCapitalCheck;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The boardroom's part of "Att göra" on Översikt (ADR-070 §3): when the
 * registered share capital in the register differs from the balance on
 * 2081 Aktiekapital, a todo names both figures. Nothing is posted; a person
 * books the difference, or corrects the register. A fixed number of queries.
 */
final class BoardroomTodos implements TodoProvider
{
    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        if (! $user->hasTeamPermission($company, 'share-register:view')) {
            return [];
        }

        try {
            $state = ShareRegister::at($today);
        } catch (RegisterException) {
            return [];
        }

        if (! $state->started) {
            return [];
        }

        $check = ShareCapitalCheck::at($state, $today);

        if ($check->matches()) {
            return [];
        }

        return [new TodoItem(
            'boardroom-share-capital',
            'task',
            __('Aktiekapitalet i aktieboken stämmer inte med bokföringen'),
            __('Aktieboken: :register (:shares aktier). Konto 2081: :ledger.', [
                'register' => Money::ofOre($check->registerCapitalOre)->format(),
                'shares' => number_format($check->registeredShares, 0, ',', ' '),
                'ledger' => Money::ofOre((int) $check->ledgerCapitalOre)->format(),
            ]),
            route('share-register.index'),
            TodoItem::PRIORITY_OTHER,
        )];
    }

    public function invalidatedBy(): array
    {
        return [ShareEvent::class, Voucher::class, OpeningBalance::class];
    }
}
