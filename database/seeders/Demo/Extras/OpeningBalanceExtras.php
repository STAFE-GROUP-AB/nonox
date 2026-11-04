<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\CarryForwardBalances;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Validation\ValidationException;

/**
 * The warning about stale opening balances (StaleOpeningBalances), for the
 * manual, in the hairdresser: the balances are carried forward into next
 * year early, and a bank fee is then booked in this year, so next year's
 * opening balances on 1930 and the result account no longer agree.
 */
final class OpeningBalanceExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-frisorer'];
    }

    public function afterAll(DemoContext $c): void
    {
        try {
            app(CarryForwardBalances::class)($c->user, $c->fiscalYear);

            $draft = app(SaveDraftVoucher::class)($c->user, $c->company, [
                'voucher_date' => $c->end->toDateString(),
                'description' => 'Bankavgift',
                'series' => 'A',
                'rows' => [
                    ['account' => 6570, 'debit_ore' => 12_500],
                    ['account' => 1930, 'credit_ore' => 12_500],
                ],
            ]);
            app(PostVoucher::class)($c->user, $draft);
        } catch (ValidationException $exception) {
            $c->warn(collect($exception->errors())->flatten()->implode(' '));
        }
    }
}
