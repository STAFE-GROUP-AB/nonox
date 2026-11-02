<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support\Export;

use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use DOMDocument;
use DOMElement;

/**
 * NONOX's own, provisional XML for the staff ledger, validated against
 * database/xsd/personalliggare-provisorisk-0.1.xsd. Every entry is exported
 * as it is stored, with its hash, so the file can be checked against the
 * chain. A foreign identity or employer number is written with its country
 * (UtlandskIdentitet, UtlandsktNummer) instead of the Swedish number.
 * TODO(SPEC): replace with Skatteverket's schema (LedgerXmlFormat).
 * TODO(legal-verify): how Skatteverket's format carries a foreign number;
 * until it is published, NONOX's own elements are used.
 */
final class ProvisionalLedgerXml implements LedgerXmlFormat
{
    public const string NAMESPACE = 'urn:nonox:personalliggare:provisorisk:0.1';

    public function render(ControlVisitReport $report): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElementNS(self::NAMESPACE, 'Personalliggare');
        $root->setAttribute('formatversion', '0.1');
        $document->appendChild($root);

        $meta = $this->element($document, $root, 'Metadata');
        $this->text($document, $meta, 'Skapad', $report->createdAt->toIso8601String());
        $this->text($document, $meta, 'Program', 'NONOX Ekonomisystem');
        $this->text($document, $meta, 'PeriodFran', $report->from->toIso8601String());
        $this->text($document, $meta, 'PeriodTill', $report->to->toIso8601String());

        $company = $this->element($document, $root, 'Foretag');
        $this->text($document, $company, 'Organisationsnummer', (string) $report->company->org_number);
        $this->text($document, $company, 'Namn', (string) ($report->company->legal_name ?: $report->company->name));

        foreach ($report->locations as $location) {
            /** @var Location $location */
            $node = $this->element($document, $root, 'Lokal');
            $node->setAttribute('id', (string) $location->id);
            $this->text($document, $node, 'Namn', $location->name);
            $this->text($document, $node, 'Adress', $location->address_line1);
            $this->text($document, $node, 'Postnummer', (string) preg_replace('/\D/', '', $location->postal_code));
            $this->text($document, $node, 'Ort', $location->city);
            $this->text($document, $node, 'Verksamhet', $location->business_type->value);

            foreach ($report->entries->where('location_id', $location->id) as $entry) {
                $this->entry($document, $node, $entry);
            }
        }

        return (string) $document->saveXML();
    }

    public function schemaPath(): string
    {
        return dirname(__DIR__, 2).'/database/xsd/personalliggare-provisorisk-0.1.xsd';
    }

    public function version(): string
    {
        return 'NONOX provisorisk 0.1';
    }

    private function entry(DOMDocument $document, DOMElement $parent, Entry $entry): void
    {
        $node = $this->element($document, $parent, 'Registrering');
        $node->setAttribute('id', (string) $entry->id);
        $this->text($document, $node, 'Typ', $entry->type->value);

        if ($entry->effectiveDirection() !== null) {
            $this->text($document, $node, 'Riktning', $entry->effectiveDirection()->value);
        }

        $this->text($document, $node, 'Tid', $entry->occurred_at->toIso8601String());
        $this->text($document, $node, 'Mottagen', $entry->received_at->toIso8601String());
        $this->text($document, $node, 'Satt', $entry->method->value);

        $person = $this->element($document, $node, 'Person');
        $this->text($document, $person, 'Namn', $entry->person_name);
        $this->identity($document, $person, 'Identitet', 'UtlandskIdentitet', $entry->personal_number, $entry->identity_country);

        if ($entry->employer_org_number !== null) {
            $employer = $this->element($document, $node, 'Arbetsgivare');
            $this->text($document, $employer, 'Namn', (string) $entry->employer_name);
            $this->identity($document, $employer, 'Organisationsnummer', 'UtlandsktNummer', $entry->employer_country === null ? (string) preg_replace('/\D/', '', $entry->employer_org_number) : $entry->employer_org_number, $entry->employer_country);
        }

        if ($entry->corrects_entry_id !== null && $entry->correction !== null) {
            $correction = $this->element($document, $node, 'Rattelse');
            $this->text($document, $correction, 'RattarRegistrering', (string) $entry->corrects_entry_id);
            $this->text($document, $correction, 'Slag', $entry->correction->value);
        }

        if ($entry->reason !== null) {
            $this->text($document, $node, 'Orsak', $entry->reason);
        }

        if ($entry->user !== null) {
            $this->text($document, $node, 'RegistreradAv', $entry->user->name);
        }

        foreach ($entry->flags as $flag) {
            $this->text($document, $node, 'Anmarkning', $flag);
        }

        if ($entry->previous_hash !== null) {
            $this->text($document, $node, 'ForegaendeHash', $entry->previous_hash);
        }

        $this->text($document, $node, 'Hash', $entry->hash);
    }

    /** A Swedish number as its own element, or a foreign number with its country. */
    private function identity(DOMDocument $document, DOMElement $parent, string $swedish, string $foreign, string $number, ?string $country): void
    {
        if ($country === null) {
            $this->text($document, $parent, $swedish, $number);

            return;
        }

        $node = $this->element($document, $parent, $foreign);
        $this->text($document, $node, 'Nummer', $number);
        $this->text($document, $node, 'Land', $country);
    }

    private function element(DOMDocument $document, DOMElement $parent, string $name): DOMElement
    {
        $element = $document->createElementNS(self::NAMESPACE, $name);
        $parent->appendChild($element);

        return $element;
    }

    private function text(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $element = $this->element($document, $parent, $name);
        $element->appendChild($document->createTextNode($value));
    }
}
