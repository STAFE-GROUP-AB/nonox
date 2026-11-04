<?php

declare(strict_types=1);

namespace App\Support\Peppol;

use App\Domain\Company\LegalForm;
use App\Domain\Company\OrganisationNumber;
use App\Models\Company;

/**
 * A Peppol participant identifier: an electronic address scheme (EAS,
 * ISO 6523 ICD) and the identifier in it, written "0007:5561234567".
 *
 * Swedish companies are addressed by their organisation number (0007,
 * ten digits, no hyphen) or a GLN (0088, thirteen digits with a GS1 check
 * digit). Other schemes are accepted when they are in the Peppol EAS code
 * list (BR-CL-25), checked for form only.
 *
 * The code list is the one shipped with Peppol BIS Billing 3.0 (May 2026
 * release, structure/codelist/eas.xml in OpenPEPPOL/peppol-bis-invoice-3,
 * read 2026-11-04).
 */
final readonly class PeppolIdentifier
{
    public const string ORGANISATION_NUMBER = '0007';

    public const string GLN = '0088';

    /** Electronic address schemes in Peppol BIS Billing 3.0 (eas.xml, May 2026). */
    public const array SCHEMES = [
        '0002', '0007', '0009', '0060', '0088', '0096', '0097', '0106', '0130', '0135', '0142', '0151', '0158', '0183',
        '0184', '0188', '0190', '0191', '0192', '0195', '0196', '0198', '0199', '0200', '0201', '0204', '0208', '0209',
        '0210', '0211', '0216', '0218', '0221', '0225', '0230', '0235', '0240', '0242', '0244', '0245', '0246', '0248',
        '9910', '9913', '9914', '9915', '9918', '9919', '9920', '9922', '9923', '9924', '9925', '9926', '9927', '9928',
        '9929', '9930', '9931', '9932', '9933', '9934', '9935', '9936', '9937', '9938', '9939', '9940', '9941', '9942',
        '9943', '9944', '9945', '9946', '9947', '9948', '9949', '9950', '9951', '9952', '9953', '9957', '9959',
    ];

    private function __construct(
        public string $scheme,
        public string $value,
    ) {}

    /**
     * "0007:5561234567", "0007:556123-4567" or "iso6523-actorid-upis::0007:5561234567";
     * null when it is not a valid identifier.
     */
    public static function tryFrom(?string $input): ?self
    {
        $input = trim((string) $input);
        $input = (string) preg_replace('/^iso6523-actorid-upis::/i', '', $input);

        if (preg_match('/^(\d{4}):(.{1,50})$/', $input, $match) !== 1) {
            return null;
        }

        return self::make($match[1], $match[2]);
    }

    public static function make(string $scheme, string $value): ?self
    {
        $value = trim($value);

        if (! in_array($scheme, self::SCHEMES, true)) {
            return null;
        }

        if ($scheme === self::ORGANISATION_NUMBER) {
            $number = OrganisationNumber::tryFrom($value);

            return $number === null ? null : new self($scheme, $number->digits);
        }

        if ($scheme === self::GLN) {
            $digits = (string) preg_replace('/\s/', '', $value);

            return self::isGln($digits) ? new self($scheme, $digits) : null;
        }

        $value = (string) preg_replace('/\s/', '', $value);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9.:_\-]{0,49}$/', $value) === 1 ? new self($scheme, $value) : null;
    }

    /**
     * Whether the company's organisation number is its owner's personal
     * identity number: a sole trader (EF), or a number whose third digit is
     * below 2. Such a company is registered in Peppol with a GLN (0088), so
     * the personal identity number is not published in the open Peppol
     * Directory (Upphandlingsmyndigheten's advice; legal review 2026-11-04).
     */
    public static function usesPersonalIdentityNumber(Company $company): bool
    {
        return $company->legal_form === LegalForm::Ef || (OrganisationNumber::tryFrom((string) $company->org_number)?->isPersonalIdentityNumber() ?? false);
    }

    /** The identifier a Swedish company gets from its organisation number, or null when it has none. */
    public static function forOrganisationNumber(?string $orgNumber): ?self
    {
        return $orgNumber === null ? null : self::make(self::ORGANISATION_NUMBER, $orgNumber);
    }

    public function toString(): string
    {
        return "{$this->scheme}:{$this->value}";
    }

    /** As written in an SMP lookup and on the network envelope. */
    public function urn(): string
    {
        return 'iso6523-actorid-upis::'.$this->toString();
    }

    public function equals(self $other): bool
    {
        return $this->scheme === $other->scheme && strcasecmp($this->value, $other->value) === 0;
    }

    /** A GLN: thirteen digits, the last a GS1 mod 10 check digit (weights 3 and 1 from the right). */
    public static function isGln(string $digits): bool
    {
        if (preg_match('/^\d{13}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits[11 - $i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) $digits[12];
    }
}
