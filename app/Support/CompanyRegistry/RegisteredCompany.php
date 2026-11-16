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
        public ?string $sourceName = null,
        public ?string $sourceDate = null,
        public ?string $sniCode = null,
        public ?int $employeeClass = null,
        public ?int $turnoverClass = null,
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

        if (preg_match('/^\d{2}$/', $code) === 1) {
            return $this->legalFormFromScbCode($code);
        }

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

    /**
     * SCB's two-digit JurForm, as the company register holds it (RegistryCodes::LEGAL_FORMS).
     * SCB's code 31 covers handelsbolag and kommanditbolag together, so the
     * name decides: the firm of a kommanditbolag must contain "kommanditbolag"
     * or "KB", that of a handelsbolag "handelsbolag" or "HB". A name that
     * says neither leaves the form unproposed.
     *
     * TODO(legal-verify): confirm the firm-name rule in firmalagen (2018:1653) for HB and KB.
     */
    private function legalFormFromScbCode(string $code): ?LegalForm
    {
        return match ($code) {
            '49' => LegalForm::Ab,
            '51', '53' => LegalForm::EkonomiskForening,
            '61' => LegalForm::IdeellForening,
            '71', '72' => LegalForm::Stiftelse,
            '31' => $this->partnershipForm(),
            default => null,
        };
    }

    private function partnershipForm(): ?LegalForm
    {
        $name = mb_strtolower($this->name);

        return match (true) {
            str_contains($name, 'kommanditbolag') || preg_match('/(^|[\s,.])kb\.?($|[\s,.])/u', $name) === 1 => LegalForm::Kb,
            str_contains($name, 'handelsbolag') || preg_match('/(^|[\s,.])hb\.?($|[\s,.])/u', $name) === 1 => LegalForm::Hb,
            default => null,
        };
    }

    public function withSource(string $name, ?string $date = null): self
    {
        return self::fromArray([...$this->toArray(), 'sourceName' => $name, 'sourceDate' => $date]);
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

    /** The street as written in an address: "SKÖNSTAVÄGEN 3 A" becomes "Skönstavägen 3 A". */
    public function formattedStreet(): ?string
    {
        return self::sentenceCase($this->street);
    }

    public function formattedCoAddress(): ?string
    {
        return self::sentenceCase($this->coAddress);
    }

    /** Text in capitals ("SKÖNSTAVÄGEN 3 A") in normal case; text that already has lower case is left as it is. */
    public static function sentenceCase(?string $text): ?string
    {
        if ($text === null || $text !== mb_strtoupper($text)) {
            return $text;
        }

        $cased = mb_convert_case(mb_strtolower($text), MB_CASE_TITLE);

        return (string) preg_replace('/\bC\/O\b/u', 'c/o', $cased);
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
            sourceName: $text('sourceName'),
            sourceDate: $text('sourceDate'),
            sniCode: $text('sniCode'),
            employeeClass: isset($data['employeeClass']) && is_numeric($data['employeeClass']) ? (int) $data['employeeClass'] : null,
            turnoverClass: isset($data['turnoverClass']) && is_numeric($data['turnoverClass']) ? (int) $data['turnoverClass'] : null,
        );
    }
}
