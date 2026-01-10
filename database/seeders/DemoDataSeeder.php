<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Party;
use App\Models\Item;
use App\Models\User;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed demo data för utveckling och testning.
     */
    public function run(): void
    {
        // Skapa test-företag
        $company = Company::firstOrCreate(
            ['org_number' => '556677-8899'],
            [
                'name' => 'Demo Företag AB',
                'address' => 'Demovägen 123, 123 45 Stockholm',
                'vat_number' => 'SE556677889901',
                'fiscal_year_start' => '2026-01-01',
                'fiscal_year_end' => '2026-12-31',
                'base_currency' => 'SEK',
                'bankgiro' => '123-4567',
                'plusgiro' => '12 34 56-7',
                'swish_number' => '1234567890',
            ]
        );

        // Skapa test-användare
        $user = User::firstOrCreate(
            ['email' => 'demo@stafe.se'],
            [
                'name' => 'Demo Användare',
                'password' => Hash::make('password'),
            ]
        );

        // Skapa team för användaren om det inte finns
        if (!$user->currentTeam) {
            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => $company->name,
                'personal_team' => true,
            ]);
            $user->current_team_id = $team->id;
            $user->save();
        }

        // Skapa test-kunder
        Party::firstOrCreate(
            [
                'company_id' => $company->id,
                'org_number' => '556123-4567',
            ],
            [
                'type' => 'customer',
                'name' => 'Acme Corp AB',
                'address' => 'Kundgatan 1, 111 11 Stockholm',
                'email' => 'faktura@acme.se',
                'payment_terms' => 30,
                'reference' => 'Anders Andersson',
            ]
        );

        Party::firstOrCreate(
            [
                'company_id' => $company->id,
                'org_number' => '556234-5678',
            ],
            [
                'type' => 'customer',
                'name' => 'Beta Consulting AB',
                'address' => 'Konsultvägen 2, 222 22 Göteborg',
                'email' => 'ekonomi@beta.se',
                'payment_terms' => 15,
                'reference' => 'Berit Bengtsson',
            ]
        );

        // Skapa test-leverantör
        Party::firstOrCreate(
            [
                'company_id' => $company->id,
                'org_number' => '556345-6789',
            ],
            [
                'type' => 'supplier',
                'name' => 'Leverantör & Partner AB',
                'address' => 'Leveransgatan 3, 333 33 Malmö',
                'email' => 'order@leverantor.se',
                'payment_terms' => 30,
            ]
        );

        // Skapa test-artiklar
        Item::firstOrCreate(
            [
                'company_id' => $company->id,
                'item_number' => 'KONSULT-TIM',
            ],
            [
                'name' => 'Konsulttjänster',
                'description' => 'Konsultering per timme',
                'unit' => 'tim',
                'price_ex_vat' => 1200.00,
                'vat_rate' => 25.00,
                'income_account' => '3000',
            ]
        );

        Item::firstOrCreate(
            [
                'company_id' => $company->id,
                'item_number' => 'LIC-STANDARD',
            ],
            [
                'name' => 'Standardlicens',
                'description' => 'Årlig licens för standardversion',
                'unit' => 'st',
                'price_ex_vat' => 5000.00,
                'vat_rate' => 25.00,
                'income_account' => '3000',
            ]
        );

        Item::firstOrCreate(
            [
                'company_id' => $company->id,
                'item_number' => 'SUPPORT',
            ],
            [
                'name' => 'Teknisk support',
                'description' => 'Support och underhåll per månad',
                'unit' => 'mån',
                'price_ex_vat' => 800.00,
                'vat_rate' => 25.00,
                'income_account' => '3000',
            ]
        );
    }
}

