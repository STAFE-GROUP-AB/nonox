<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Agi;

use App\Modules\Payroll\Domain\PersonalNumber;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * Who an individuppgift is about. A payee with a Swedish personal or
 * coordination number is identified by it (ruta 215). One without is
 * identified by födelsetid (222) and must also give birthplace (077) with
 * its country (078), citizenship (081), name (216, 217) and an address
 * (218–220, with 221 abroad; or 230 with 221): förordning (2011:1261) om
 * skatteförfarandet 6 kap. 1 § andra stycket and Skatteverket's
 * agbeskrivning (enligt granskning 2026-11-02, juristgranskning återstår,
 * AUD-LON-15, #74).
 *
 * Citizenship (081) and a foreign tax number (252 with 076) are written
 * whenever the employee card has them; SFF 6 kap. 2 § asks for both for a
 * payee with limited tax liability.
 * TODO(legal-verify): whether the foreign tax number must be required for
 * every SINK and A-SINK payee (SFF 6 kap. 2 § has exceptions; here it is
 * optional).
 */
final readonly class AgiPayee
{
    /** Skatteverket: "nnn kan vara vilket heltal som helst mellan 000 - 999". */
    private const int BIRTH_SERIAL_MODULO = 1000;

    private function __construct(
        public ?string $personalNumber,
        public ?string $birthTime = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $birthPlace = null,
        public ?string $birthCountry = null,
        public ?string $citizenship = null,
        public ?string $street = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $addressCountry = null,
        public ?string $tin = null,
        public ?string $tinCountry = null,
    ) {}

    /**
     * @throws ValidationException when the employee can be identified neither way
     */
    public static function for(?Employee $employee): self
    {
        $name = $employee === null ? '' : "{$employee->first_name} {$employee->last_name}";

        if ($employee === null) {
            throw self::missing($name);
        }

        $tin = blank($employee->foreign_tin) || blank($employee->foreign_tin_country) ? [null, null] : [(string) $employee->foreign_tin, (string) $employee->foreign_tin_country];

        if ($employee->hasSwedishNumber()) {
            $number = PersonalNumber::tryFrom((string) $employee->personal_number) ?? throw self::missing($name);

            return new self($number->digits, citizenship: $employee->citizenship, tin: $tin[0], tinCountry: $tin[1]);
        }

        foreach ([$employee->birth_place, $employee->birth_country, $employee->citizenship, $employee->address, $employee->city] as $value) {
            if (blank($value)) {
                throw ValidationException::withMessages(['period' => [__(':name saknar svenskt personnummer. Fyll i födelseort, födelseland, medborgarskap och adress under Lön → Anställda.', ['name' => $name])]]);
            }
        }

        // The three last digits only tell apart payees born the same day; the employee number keeps them stable between corrections.
        $serial = str_pad((string) ($employee->employee_number % self::BIRTH_SERIAL_MODULO), 3, '0', STR_PAD_LEFT);

        return new self(
            personalNumber: null,
            birthTime: $employee->birth_date->format('Ymd').$serial,
            firstName: mb_substr($employee->first_name, 0, 50),
            lastName: mb_substr($employee->last_name, 0, 50),
            birthPlace: (string) $employee->birth_place,
            birthCountry: (string) $employee->birth_country,
            citizenship: $employee->citizenship,
            street: (string) $employee->address,
            postalCode: blank($employee->postal_code) ? null : (string) $employee->postal_code,
            city: (string) $employee->city,
            addressCountry: $employee->country,
            tin: $tin[0],
            tinCountry: $tin[1],
        );
    }

    private static function missing(string $name): ValidationException
    {
        return ValidationException::withMessages(['period' => [__('Personnummer saknas eller är fel för :name. Rätta det under Lön → Anställda.', ['name' => $name])]]);
    }
}
