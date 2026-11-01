<?php

declare(strict_types=1);

use App\Domain\Company\OrganisationNumber;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Support\Landing\Industries;
use Database\Seeders\Demo\DemoIndustries;

it('has a demo company for every industry page', function () {
    expect(array_keys(DemoIndustries::all()))->toEqualCanonicalizing(array_keys(Industries::all()));
});

it('uses only synthetic, valid and distinct organisation numbers', function () {
    $companies = array_map(fn (array $demo): string => DemoIndustries::orgNumber($demo['org_serial'], '559', '9998'), DemoIndustries::all());
    $contacts = [];

    foreach (DemoIndustries::all() as $demo) {
        foreach ([...$demo['customers'], ...$demo['suppliers']] as $contact) {
            if (isset($contact['org_number'])) {
                $contacts[$contact['name']] = $contact['org_number'];
            }
        }
    }

    foreach ([...array_values($companies), ...array_values($contacts)] as $number) {
        expect(OrganisationNumber::tryFrom($number))->not->toBeNull("{$number} is not a valid organisation number.")
            ->and(substr(str_replace('-', '', $number), 3, 3))->toBe('999', "{$number} is outside the demo range.");
    }

    expect(array_unique(array_values($companies)))->toHaveCount(count($companies))
        ->and(array_intersect($companies, $contacts))->toBe([]);
});

it('uses valid personal numbers for employees and ROT customers', function () {
    foreach (DemoIndustries::all() as $demo) {
        foreach ($demo['customers'] as $customer) {
            if (isset($customer['personal_number'])) {
                expect(PersonalNumber::tryFrom($customer['personal_number']))->not->toBeNull($customer['name']);
            }
        }

        foreach ($demo['employees'] as $index => $employee) {
            $number = DemoIndustries::personalNumber($employee['birth'], '9'.str_pad((string) ($index + 10), 2, '0', STR_PAD_LEFT));

            expect(PersonalNumber::tryFrom($number))->not->toBeNull("{$employee['first_name']} {$employee['last_name']}");
        }
    }
});
