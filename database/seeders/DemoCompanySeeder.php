<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Company\CreateCompany;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Developer convenience: a demo user and a synthetic demo company. Not part of
 * the normal installation, where the first-run guide on "/" creates the real
 * company. Never run on a server that is reachable from the internet.
 */
class DemoCompanySeeder extends Seeder
{
    public const string DEMO_ORG_NUMBER = '559999-9991';

    public function run(CreateCompany $createCompany): void
    {
        $user = User::firstOrCreate(
            ['email' => config('app.admin_email') ?: 'demo@example.com'],
            [
                'name' => 'Demo Användare',
                'email_verified_at' => now(),
            ],
        );

        if ($user->companies()->isNotEmpty()) {
            return;
        }

        $createCompany->create($user, [
            'name' => 'Demo AB',
            'org_number' => self::DEMO_ORG_NUMBER,
            'legal_form' => 'AB',
            'address_line1' => 'Exempelgatan 1',
            'postal_code' => '111 22',
            'city' => 'Stockholm',
            'fiscal_year_start_month' => 1,
            'accounting_method' => 'FAKTURAMETODEN',
            'vat_registered' => true,
            'vat_period' => 'QUARTERLY',
            'reporting_framework' => 'K2',
            'framework_confirmed' => true,
            'f_skatt' => true,
            'employer_registered' => false,
        ]);
    }
}
