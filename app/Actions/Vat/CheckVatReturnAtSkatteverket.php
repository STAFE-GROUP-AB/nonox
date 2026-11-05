<?php

declare(strict_types=1);

namespace App\Actions\Vat;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Skatteverket\Moms\MomsPeriod;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Lets Skatteverket check the period's VAT return without saving anything
 * there (ADR-054, "Kontrollera utkast"). Nothing is stored in NONOX either.
 */
class CheckVatReturnAtSkatteverket
{
    /**
     * @return array{status: string, resultat: list<array{status: string, kod: string, beskrivning: string}>}
     *
     * @throws SkatteverketFailed
     */
    public function __invoke(User $user, FiscalYear $fiscalYear, CarbonImmutable $start): array
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'vat:prepare')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $start): array {
            $moms = MomsPeriod::resolve($user, $company, $fiscalYear, $start);
            $result = $moms->api->check($moms->redovisare, $moms->code, $moms->payload());

            Audit::record('vat.skatteverket_checked', null, ['period_end' => $moms->period['end']->toDateString(), 'status' => $result['status']], $company, $user);

            return $result;
        });
    }
}
