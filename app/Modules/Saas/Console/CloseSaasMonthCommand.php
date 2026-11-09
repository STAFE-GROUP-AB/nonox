<?php

declare(strict_types=1);

namespace App\Modules\Saas\Console;

use App\Models\User;
use App\Modules\Saas\Actions\CloseSaasMonth;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Closes a month for a named person (O6: months are closed by a person, never
 * by the calendar). For operators acting on a person's request; the person
 * needs saas:manage, and the same checks apply as for "Stäng månad".
 */
class CloseSaasMonthCommand extends Command
{
    protected $signature = 'saas:close-month {company : Företagets id} {month : Månaden, ÅÅÅÅ-MM} {--user= : Id för personen som stänger}';

    protected $description = 'Stänger en månad i Återkommande intäkter för en person med behörighet';

    public function handle(CloseSaasMonth $close): int
    {
        $company = Companies::from((string) $this->argument('company'))[0] ?? null;
        $user = User::query()->find((int) $this->option('user'));

        if ($company === null || ! $user instanceof User) {
            $this->error('Ange ett företag som finns och --user med id för personen som stänger.');

            return self::INVALID;
        }

        try {
            $month = $close($user, $company, (string) $this->argument('month'));
        } catch (ValidationException $exception) {
            $this->error((string) collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->info("Stängd: {$month->key()}.");

        return self::SUCCESS;
    }
}
