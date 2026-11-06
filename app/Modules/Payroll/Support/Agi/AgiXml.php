<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Agi;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use LibXMLError;

/**
 * Writes an arbetsgivardeklaration in Skatteverket's XML format, schema
 * version 1.1 (instance and component parts): Avsandare, Blankettgemensamt,
 * one Blankett with the huvuduppgift and one per individuppgift. Field codes
 * (faltkod) are fixed by the schema for each element.
 *
 * A correction resends the individuppgift with the same specification
 * number, which replaces the earlier one; a payee no longer in the period
 * gets an individuppgift with only Borttag (removal).
 */
final class AgiXml
{
    public const string INSTANCE_NS = 'http://xmls.skatteverket.se/se/skatteverket/da/instans/schema/1.1';

    public const string COMPONENT_NS = 'http://xmls.skatteverket.se/se/skatteverket/da/komponent/schema/1.1';

    private const string SCHEMA_LOCATION = 'http://xmls.skatteverket.se/se/skatteverket/da/arbetsgivardeklaration/arbetsgivardeklaration_1.1.xsd';

    /** Element => faltkod, as fixed in the component schema. */
    public const array FIELD_CODES = [
        'AgRegistreradId' => '201',
        'RedovisningsPeriod' => '006',
        'SummaArbAvgSlf' => '487',
        'SummaSkatteavdr' => '497',
        'UlagRegionaltStod' => '471',
        'AvdragRegionaltStod' => '476',
        'BetalningsmottagarId' => '215',
        'Fodelsetid' => '222',
        'Fornamn' => '216',
        'Efternamn' => '217',
        'Gatuadress' => '218',
        'Postnummer' => '219',
        'Postort' => '220',
        'LandskodPostort' => '221',
        'FriAdress' => '230',
        'Fodelseort' => '077',
        'LandskodFodelseort' => '078',
        'LandskodMedborgare' => '081',
        'TIN' => '252',
        'LandskodTIN' => '076',
        'Specifikationsnummer' => '570',
        'KontantErsattningUlagAG' => '011',
        'SkatteplOvrigaFormanerUlagAG' => '012',
        'SkatteplBilformanUlagAG' => '013',
        'DrivmVidBilformanUlagAG' => '018',
        'BetForDrivmVidBilformanUlagAG' => '098',
        'AvrakningAvgiftsfriErs' => '010',
        'KontantErsattningEjUlagSA' => '131',
        'SkatteplOvrigaFormanerEjUlagSA' => '132',
        'SkatteplBilformanEjUlagSA' => '133',
        'DrivmVidBilformanEjUlagSA' => '134',
        'AvdrPrelSkatt' => '001',
        'AvdrSkattSINK' => '274',
        'AvdrSkattASINK' => '275',
        'VerksamhetensArt' => '112',
        'Bilersattning' => '050',
        'Traktamente' => '051',
        'Borttag' => '205',
    ];

    private const array TAX_ELEMENTS = [
        AgiIndividual::TAX_PRELIMINARY => 'AvdrPrelSkatt',
        AgiIndividual::TAX_SINK => 'AvdrSkattSINK',
        AgiIndividual::TAX_A_SINK => 'AvdrSkattASINK',
    ];

    private DOMDocument $document;

    /**
     * @param  array{program: string, name: string, phone: string, email: string}  $contact
     * @param  list<array{payee: AgiPayee, individual: AgiIndividual}>  $individuals
     * @param  list<array{payee: AgiPayee, specification: int}>  $removals
     * @param  ?array{basis: int, deduction: int}  $regionalSupport  rutor 471 and 476 in whole kronor, always together (#78)
     */
    public function write(
        string $employerId,
        string $period,
        array $contact,
        int $contributionsKronor,
        int $taxKronor,
        array $individuals,
        array $removals,
        CarbonImmutable $created,
        ?array $regionalSupport = null,
    ): string {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;
        $redovisningsperiod = str_replace('-', '', $period);

        $root = $this->document->createElementNS(self::INSTANCE_NS, 'Skatteverket');
        $root->setAttribute('omrade', 'Arbetsgivardeklaration');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:agd', self::COMPONENT_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', self::INSTANCE_NS.' '.self::SCHEMA_LOCATION);
        $this->document->appendChild($root);

        $sender = $this->add($root, 'Avsandare');
        $this->add($sender, 'Programnamn', $contact['program']);
        $this->add($sender, 'Organisationsnummer', $employerId);
        $this->person($this->add($sender, 'TekniskKontaktperson'), $contact);
        $this->add($sender, 'Skapad', $created->format('Y-m-d\TH:i:s'));

        $employer = $this->add($this->add($root, 'Blankettgemensamt'), 'Arbetsgivare');
        $this->add($employer, 'AgRegistreradId', $employerId);
        $this->person($this->add($employer, 'Kontaktperson'), $contact);

        $hu = $this->add($this->form($root, $employerId, $redovisningsperiod), 'HU');
        $this->field($this->add($hu, 'ArbetsgivareHUGROUP'), 'AgRegistreradId', $employerId);
        $this->field($hu, 'RedovisningsPeriod', $redovisningsperiod);
        $this->field($hu, 'SummaArbAvgSlf', (string) $contributionsKronor);
        $this->field($hu, 'SummaSkatteavdr', (string) $taxKronor);

        if ($regionalSupport !== null) {
            $this->field($hu, 'UlagRegionaltStod', (string) $regionalSupport['basis']);
            $this->field($hu, 'AvdragRegionaltStod', (string) $regionalSupport['deduction']);
        }

        foreach ($individuals as $entry) {
            $individual = $entry['individual'];
            $iu = $this->individual($root, $employerId, $redovisningsperiod, $entry['payee'], $individual->specificationNumber);

            $this->amount($iu, 'KontantErsattningUlagAG', $individual->cashKronor);
            $this->amount($iu, 'SkatteplOvrigaFormanerUlagAG', $individual->otherBenefitsKronor, $individual->hadOtherBenefit);
            $this->amount($iu, 'SkatteplBilformanUlagAG', $individual->carBenefitKronor, $individual->hadCarBenefit || $individual->fuelBenefitKronor > 0);
            $this->amount($iu, 'DrivmVidBilformanUlagAG', $individual->fuelBenefitKronor);
            $this->amount($iu, 'BetForDrivmVidBilformanUlagAG', $individual->fuelPaidKronor);
            $this->amount($iu, 'AvrakningAvgiftsfriErs', $individual->earlierFreeKronor);
            $this->amount($iu, 'KontantErsattningEjUlagSA', $individual->freeCashKronor);
            $this->amount($iu, 'SkatteplOvrigaFormanerEjUlagSA', $individual->freeOtherBenefitsKronor);
            $this->amount($iu, 'SkatteplBilformanEjUlagSA', $individual->freeCarBenefitKronor);
            $this->amount($iu, 'DrivmVidBilformanEjUlagSA', $individual->freeFuelBenefitKronor);
            $this->field($iu, self::TAX_ELEMENTS[$individual->taxForm], (string) $individual->taxKronor);

            if ($individual->taxForm === AgiIndividual::TAX_A_SINK) {
                $this->field($iu, 'VerksamhetensArt', (string) $individual->activityType);
            }

            if ($individual->mileage) {
                $this->field($iu, 'Bilersattning', '1');
            }

            if ($individual->perDiem) {
                $this->field($iu, 'Traktamente', '1');
            }
        }

        foreach ($removals as $removal) {
            $iu = $this->individual($root, $employerId, $redovisningsperiod, $removal['payee'], $removal['specification']);
            $this->field($iu, 'Borttag', '1');
        }

        return (string) $this->document->saveXML();
    }

    /**
     * Validates against Skatteverket's schema files, when a directory with
     * arbetsgivardeklaration_1.1.xsd and its component part is given.
     *
     * @return list<string> the schema errors, empty when valid
     */
    public static function validate(string $xml, string $schemaDirectory): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $document->loadXML($xml);
            if ($document->schemaValidate(rtrim($schemaDirectory, '/').'/arbetsgivardeklaration_1.1.xsd')) {
                return [];
            }

            // The schema's own metadata gives a warning about a relative namespace; only errors count.
            $errors = array_filter(libxml_get_errors(), fn (LibXMLError $error): bool => $error->level >= LIBXML_ERR_ERROR);

            return array_values(array_map(fn (LibXMLError $error): string => self::withoutValues(trim($error->message))." (rad {$error->line})", $errors));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function form(DOMElement $root, string $employerId, string $period): DOMElement
    {
        $form = $this->add($root, 'Blankett');
        $case = $this->add($form, 'Arendeinformation');
        $this->add($case, 'Arendeagare', $employerId);
        $this->add($case, 'Period', $period);

        return $this->add($form, 'Blankettinnehall');
    }

    private function individual(DOMElement $root, string $employerId, string $period, AgiPayee $payee, int $specification): DOMElement
    {
        $iu = $this->add($this->form($root, $employerId, $period), 'IU');
        $this->field($this->add($iu, 'ArbetsgivareIUGROUP'), 'AgRegistreradId', $employerId);
        $this->payee($this->add($iu, 'BetalningsmottagareIUGROUP'), $payee);
        $this->field($iu, 'RedovisningsPeriod', $period);
        $this->field($iu, 'Specifikationsnummer', (string) $specification);

        return $iu;
    }

    /**
     * The payee's identity: the Swedish number (215), or födelsetid (222)
     * with name, birthplace and address (#74). Citizenship and a foreign tax
     * number go with either when known.
     */
    private function payee(DOMElement $group, AgiPayee $payee): void
    {
        $choice = $this->add($group, 'BetalningsmottagareIDChoice');

        if ($payee->personalNumber !== null) {
            $this->field($choice, 'BetalningsmottagarId', $payee->personalNumber);
        } else {
            $this->field($choice, 'Fodelsetid', (string) $payee->birthTime);
            $this->optional($group, 'Fornamn', $payee->firstName);
            $this->optional($group, 'Efternamn', $payee->lastName);
            $this->optional($group, 'Fodelseort', $payee->birthPlace);
            $this->optional($group, 'LandskodFodelseort', $payee->birthCountry);
            $this->address($group, $payee);
        }

        $this->optional($group, 'LandskodMedborgare', $payee->citizenship);
        $this->optional($group, 'TIN', $payee->tin);
        $this->optional($group, 'LandskodTIN', $payee->tinCountry);
    }

    /**
     * Gatuadress, postnummer and postort (218–220), with the country (221)
     * when abroad. An address without a postal code goes in Fri adress (230),
     * which Skatteverket says must always come with 221.
     */
    private function address(DOMElement $group, AgiPayee $payee): void
    {
        $abroad = $payee->addressCountry !== null && $payee->addressCountry !== 'SE';

        if ($payee->postalCode === null) {
            $this->optional($group, 'FriAdress', mb_substr(implode(', ', array_filter([$payee->street, $payee->city])), 0, 200));
            $this->optional($group, 'LandskodPostort', $payee->addressCountry ?? 'SE');

            return;
        }

        $this->optional($group, 'Gatuadress', $payee->street);
        $this->optional($group, 'Postnummer', $payee->postalCode);
        $this->optional($group, 'Postort', $payee->city);

        if ($abroad) {
            $this->field($group, 'LandskodPostort', (string) $payee->addressCountry);
        }
    }

    private function optional(DOMElement $parent, string $name, ?string $value): void
    {
        if ($value !== null && trim($value) !== '') {
            $this->field($parent, $name, $value);
        }
    }

    /** @param array{name: string, phone: string, email: string} $contact */
    private function person(DOMElement $parent, array $contact): void
    {
        $this->add($parent, 'Namn', $contact['name']);
        $this->add($parent, 'Telefon', $contact['phone']);
        $this->add($parent, 'Epostadress', $contact['email']);
    }

    /** An amount above 0, or 0 when Skatteverket asks for a nought (012 and 013 for a benefit that existed, #79). */
    private function amount(DOMElement $parent, string $name, int $kronor, bool $evenWhenZero = false): void
    {
        if ($kronor > 0 || ($evenWhenZero && $kronor === 0)) {
            $this->field($parent, $name, (string) $kronor);
        }
    }

    private function field(DOMElement $parent, string $name, string $value): void
    {
        $this->add($parent, $name, $value)->setAttribute('faltkod', self::FIELD_CODES[$name]);
    }

    private function add(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $this->document->createElementNS(self::COMPONENT_NS, 'agd:'.$name);

        if ($value !== null) {
            $element->appendChild($this->document->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }

    /**
     * libxml quotes the offending value (a personal number, an amount):
     * keep the element names and hide the values, so the message can be
     * shown and logged without personal data.
     */
    public static function withoutValues(string $message): string
    {
        return (string) preg_replace_callback(
            "/(Element\\s+)?'([^']*)'/",
            fn (array $match): string => $match[1] !== '' ? $match[0] : "'…'",
            $message,
        );
    }
}
