<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Seeder;

class BasAccountSeeder extends Seeder
{
    /**
     * BAS-kontoplan för svenska företag.
     * Detta är en förenklad version med de mest använda kontona.
     */
    public function run(): void
    {
        // Hämta första företaget
        $company = Company::first();
        
        if (!$company) {
            // Skapa ett standard-företag för utveckling om inget finns
            $company = Company::create([
                'org_number' => '000000-0000',
                'name' => 'Standard Företag AB',
                'fiscal_year_start' => now()->startOfYear(),
                'fiscal_year_end' => now()->endOfYear(),
                'base_currency' => 'SEK',
            ]);
        }

        $accounts = [
            // TILLGÅNGAR (Assets) - Klass 1
            ['1510', 'Kundfordringar', 'asset'],
            ['1511', 'Osäkra kundfordringar', 'asset'],
            ['1910', 'Kassa', 'asset'],
            ['1920', 'PlusGiro', 'asset'],
            ['1930', 'Checkräkningskonto', 'asset'],
            ['1940', 'Övriga bankkonton', 'asset'],
            
            // SKULDER (Liabilities) - Klass 2
            ['2440', 'Leverantörsskulder', 'liability'],
            ['2510', 'Skatteskulder', 'liability'],
            ['2610', 'Utgående moms, 25%', 'liability'],
            ['2611', 'Utgående moms, 12%', 'liability'],
            ['2612', 'Utgående moms, 6%', 'liability'],
            ['2640', 'Ingående moms', 'liability'],
            ['2650', 'Redovisningskonto för moms', 'liability'],
            
            // EGET KAPITAL (Equity) - Klass 2
            ['2081', 'Årets resultat', 'equity'],
            ['2091', 'Balanserad vinst eller förlust', 'equity'],
            
            // INTÄKTER (Income) - Klass 3
            ['3000', 'Försäljning varor/tjänster, 25% moms', 'income'],
            ['3001', 'Försäljning varor/tjänster, 12% moms', 'income'],
            ['3002', 'Försäljning varor/tjänster, 6% moms', 'income'],
            ['3003', 'Försäljning varor/tjänster, momsfritt', 'income'],
            ['3740', 'Öresutjämning', 'income'],
            
            // KOSTNADER (Expenses) - Klass 4-8
            ['4000', 'Inköp av varor', 'expense'],
            ['5010', 'Lokalhyra', 'expense'],
            ['5800', 'Resor', 'expense'],
            ['6071', 'Representation', 'expense'],
            ['6570', 'Bankkostnader', 'expense'],
            ['7010', 'Löner till kollektivanställda', 'expense'],
            ['7510', 'Arbetsgivaravgifter', 'expense'],
        ];

        foreach ($accounts as [$number, $name, $type]) {
            Account::firstOrCreate(
                [
                    'company_id' => $company->id,
                    'account_number' => $number,
                ],
                [
                    'name' => $name,
                    'type' => $type,
                    'active' => true,
                ]
            );
        }
    }
}

