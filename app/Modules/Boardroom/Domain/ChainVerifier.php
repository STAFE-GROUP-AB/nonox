<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use App\Models\Company;
use App\Modules\Boardroom\Models\Register;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Support\Tenancy\CurrentCompany;

/**
 * Recomputes a company's share register chain (`share-register:verify`),
 * the register's counterpart to LedgerVerifier: every entry must link to the
 * one before it, hash to what is stored, follow the sequence without gaps,
 * and the chain must end at the head kept on the register row. The fold is
 * run too, so an entry that breaks the share ranges is reported.
 */
final class ChainVerifier
{
    /**
     * @return array{ok: bool, entries: int, problems: list<string>}
     */
    public function verify(Company $company): array
    {
        return CurrentCompany::run($company, function (): array {
            $problems = [];
            $previous = null;
            $expected = 1;
            $count = 0;

            foreach (ShareEvent::query()->orderBy('sequence')->lazyById(500) as $event) {
                $count++;

                if ($event->sequence !== $expected) {
                    $problems[] = __('Händelse :number saknas. En händelse kan ha tagits bort.', ['number' => $expected]);
                }

                if ($event->previous_hash !== $previous) {
                    $problems[] = __('Kedjan är bruten före händelse :number. En händelse kan ha tagits bort.', ['number' => $event->sequence]);
                }

                if (EventHash::of($event, $event->previous_hash) !== $event->hash) {
                    $problems[] = __('Händelse :number har ändrats efter att den skrevs.', ['number' => $event->sequence]);
                }

                $previous = $event->hash;
                $expected = $event->sequence + 1;
            }

            $register = Register::query()->first();

            if ($previous !== $register?->head_hash) {
                $problems[] = __('Kedjans sista länk stämmer inte med den sparade. En händelse kan ha tagits bort.');
            }

            try {
                RegisterFold::at(ShareEvent::query()->orderBy('sequence')->get());
            } catch (RegisterException $exception) {
                $problems[] = __('Aktieboken går inte ihop: :problem', ['problem' => $exception->getMessage()]);
            }

            return ['ok' => $problems === [], 'entries' => $count, 'problems' => $problems];
        });
    }
}
