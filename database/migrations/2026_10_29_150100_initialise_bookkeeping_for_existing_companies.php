<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Models\Company;
use Illuminate\Database\Migrations\Migration;

/**
 * Companies created before the chart of accounts existed get it now: the BAS
 * template, their first fiscal year and the default voucher series. It only
 * adds what is missing, so it is safe on any installation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $initialise = app(InitialiseBookkeeping::class);

        Company::query()->orderBy('id')->each(function (Company $company) use ($initialise): void {
            $initialise($company);
        });
    }
};
