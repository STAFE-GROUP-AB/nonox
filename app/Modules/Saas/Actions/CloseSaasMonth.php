<?php

declare(strict_types=1);

namespace App\Modules\Saas\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Saas\Domain\Months;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Support\SaasLedger;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Stäng månad" (ADR-080 §4, O6): a person with saas:manage freezes a month
 * that has ended. Months close in order. The month is recomputed first, then
 * stored with the last version it used and its rates; from then on nothing
 * changes it, and later knowledge becomes a correction in the next open month.
 */
final class CloseSaasMonth
{
    public function __construct(private readonly SaasLedger $ledger) {}

    /**
     * @param  string  $month  "2026-10" or "2026-10-01"
     */
    public function __invoke(User $user, Company $company, string $month, ?CarbonImmutable $today = null): SaasMonth
    {
        app(ModuleManager::class)->ensureEnabled(SaasModule::KEY, $company);

        if (! $user->hasTeamPermission($company, SaasModule::MANAGE)) {
            throw new AuthorizationException(__('Du har inte behörighet att stänga månader.'));
        }

        if (preg_match('/^(\d{4}-\d{2})(-01)?$/', $month, $match) !== 1) {
            throw ValidationException::withMessages(['month' => __('Ange månaden som ÅÅÅÅ-MM.')]);
        }

        $month = $match[1].'-01';
        $today ??= CarbonImmutable::today();

        return CurrentCompany::run($company, fn (): SaasMonth => DB::transaction(function () use ($user, $company, $month, $today): SaasMonth {
            // The month must exist in the ledger before it can be the first open one.
            $this->ledger->recompute($company, $today);
            $closable = SaasLedger::closable($today);

            if ($closable === null || $closable->key() !== $month) {
                throw ValidationException::withMessages(['month' => $closable === null
                    ? __('Det finns ingen månad att stänga än. En månad kan stängas när den är slut.')
                    : __('Stäng :month först. Månaderna stängs i tur och ordning.', ['month' => Months::label($closable->key())])]);
            }

            // A month that uses a carried rate is not final yet (review 9).
            if ((array) $closable->missing_rates !== []) {
                throw ValidationException::withMessages(['month' => __('Riksbanken har ingen kurs för :currencies vid slutet av :month. Månaden kan stängas när kursen finns.', [
                    'currencies' => implode(', ', (array) $closable->missing_rates),
                    'month' => Months::label($closable->key()),
                ])]);
            }

            $closed = $this->ledger->close($company, $month, $user->id, $today);

            Audit::record('saas.month_closed', $closed, [
                'month' => $month,
                'versions_known_until' => $closed->versions_known_until,
            ], $company, $user);

            return $closed;
        }));
    }
}
