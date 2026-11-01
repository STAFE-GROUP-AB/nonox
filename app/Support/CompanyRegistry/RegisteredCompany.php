<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

use App\Domain\Company\LegalForm;

/**
 * What a register says about one organisation. Only the fields the forms
 * can use, plus what the user must see before trusting the answer:
 * deregistration and ongoing bankruptcy, liquidation or reorganisation.
 */
final readonly class RegisteredCompany
{
    /**
     * @param  list<string>  $proceedings  ongoing winding-up or restructuring, in the register's words
     */
    public function __construct(
        public string $name,
        public ?string $legalFormCode = null,
        public ?string $legalFormText = null,
        public ?string $street = null,
        public ?string $coAddress = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $registeredOn = null,
        public ?string $deregisteredOn = null,
        public array $proceedings = [],
    ) {}

    /**
     * Our legal form for the register's organisationsform. The codes AB
     * and E are the ones in Bolagsverket's published examples; the other
     * forms are matched on the register's plain-text name.
     *
     * TODO(verify): Bolagsverket's full list of organisationsform codes is
     * not in the OpenAPI specification; confirm the codes for HB, KB,
     * ekonomisk förening, ideell förening and stiftelse with a test call.
     */
    public function legalForm(): ?LegalForm
    {
        $code = strtoupper((string) $this->legalFormCode);
        $text = mb_strtolower(trim((string) $this->legalFormText));

        return match (true) {
            $code === 'AB' || $text === 'aktiebolag' => LegalForm::Ab,
            $code === 'E' || $text === 'enskild näringsidkare' => LegalForm::Ef,
            $text === 'handelsbolag' => LegalForm::Hb,
            $text === 'kommanditbolag' => LegalForm::Kb,
            $text === 'ekonomisk förening' => LegalForm::EkonomiskForening,
            $text === 'ideell förening' => LegalForm::IdeellForening,
            str_contains($text, 'stiftelse') => LegalForm::Stiftelse,
            default => null,
        };
    }

    public function isActive(): bool
    {
        return $this->deregisteredOn === null;
    }

    /** Postal code as "123 45" when the register gives five digits. */
    public function formattedPostalCode(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->postalCode) ?? '';

        if (strlen($digits) !== 5) {
            return $this->postalCode;
        }

        return substr($digits, 0, 3).' '.substr($digits, 3);
    }

    /** The city as written in an address: registers often answer in capitals ("ESLÖV"). */
    public function formattedCity(): ?string
    {
        if ($this->city === null || $this->city !== mb_strtoupper($this->city)) {
            return $this->city;
        }

        return mb_convert_case(mb_strtolower($this->city), MB_CASE_TITLE);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $text = fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return new self(
            name: (string) $text('name'),
            legalFormCode: $text('legalFormCode'),
            legalFormText: $text('legalFormText'),
            street: $text('street'),
            coAddress: $text('coAddress'),
            postalCode: $text('postalCode'),
            city: $text('city'),
            country: $text('country'),
            registeredOn: $text('registeredOn'),
            deregisteredOn: $text('deregisteredOn'),
            proceedings: array_values(array_filter((array) ($data['proceedings'] ?? []), 'is_string')),
        );
    }
}
