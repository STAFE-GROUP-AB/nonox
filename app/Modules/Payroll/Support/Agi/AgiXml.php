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
        'BetalningsmottagarId' => '215',
        'Specifikationsnummer' => '570',
        'KontantErsattningUlagAG' => '011',
        'SkatteplOvrigaFormanerUlagAG' => '012',
        'SkatteplBilformanUlagAG' => '013',
        'DrivmVidBilformanUlagAG' => '018',
        'AvdrPrelSkatt' => '001',
        'AvdrSkattSINK' => '274',
        'AvdrSkattASINK' => '275',
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
     * @param  list<array{personal_number: string, individual: AgiIndividual}>  $individuals
     * @param  list<array{personal_number: string, specification: int}>  $removals
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

        foreach ($individuals as $entry) {
            $individual = $entry['individual'];
            $iu = $this->individual($root, $employerId, $redovisningsperiod, $entry['personal_number'], $individual->specificationNumber);

            $this->amount($iu, 'KontantErsattningUlagAG', $individual->cashKronor);
            $this->amount($iu, 'SkatteplOvrigaFormanerUlagAG', $individual->otherBenefitsKronor);
            $this->amount($iu, 'SkatteplBilformanUlagAG', $individual->carBenefitKronor);
            $this->amount($iu, 'DrivmVidBilformanUlagAG', $individual->fuelBenefitKronor);
            $this->field($iu, self::TAX_ELEMENTS[$individual->taxForm], (string) $individual->taxKronor);

            if ($individual->mileage) {
                $this->field($iu, 'Bilersattning', '1');
            }

            if ($individual->perDiem) {
                $this->field($iu, 'Traktamente', '1');
            }
        }

        foreach ($removals as $removal) {
            $iu = $this->individual($root, $employerId, $redovisningsperiod, $removal['personal_number'], $removal['specification']);
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

            return array_values(array_map(fn (LibXMLError $error): string => trim($error->message)." (rad {$error->line})", $errors));
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

    private function individual(DOMElement $root, string $employerId, string $period, string $personalNumber, int $specification): DOMElement
    {
        $iu = $this->add($this->form($root, $employerId, $period), 'IU');
        $this->field($this->add($iu, 'ArbetsgivareIUGROUP'), 'AgRegistreradId', $employerId);
        $this->field($this->add($this->add($iu, 'BetalningsmottagareIUGROUP'), 'BetalningsmottagareIDChoice'), 'BetalningsmottagarId', $personalNumber);
        $this->field($iu, 'RedovisningsPeriod', $period);
        $this->field($iu, 'Specifikationsnummer', (string) $specification);

        return $iu;
    }

    /** @param array{name: string, phone: string, email: string} $contact */
    private function person(DOMElement $parent, array $contact): void
    {
        $this->add($parent, 'Namn', $contact['name']);
        $this->add($parent, 'Telefon', $contact['phone']);
        $this->add($parent, 'Epostadress', $contact['email']);
    }

    private function amount(DOMElement $parent, string $name, int $kronor): void
    {
        if ($kronor > 0) {
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
}
