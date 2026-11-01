<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Paxml;

use DOMElement;
use DOMNode;
use ErrorException;
use XMLReader;

/**
 * Reads the transactions of a PAXml file (paxml.se), the Swedish standard
 * for payroll data from scheduling and time systems, versions 1.0 and 2.0
 * to 2.3 per the official schemas (https://www.paxml.se/{version}/paxml.xsd).
 * The schemas have no namespace; the root element is <paxml>.
 *
 * - <lonetransaktioner>/<lonetrans anstid persnr>: lonart (the receiving
 *   system's code, free text) or lonkod (PAXml's standard code), benamning,
 *   datum or datumfrom/datumtom, antal, apris and belopp.
 * - <tidtransaktioner>/<tidtrans anstid persnr>: tidkod, datum or
 *   datumfrom/datumtom, timmar.
 * - Everything else (header aside) is counted and left unread: schedules,
 *   personnel records with bank accounts, travel, payslips and balances.
 *
 * The file is read as a stream, node by node. It is never given a chance to
 * reach the network or expand entities: libxml may not fetch anything
 * (LIBXML_NONET), entities are not substituted, no DTD is loaded, and a
 * file with a DOCTYPE is refused before any element is read. Size and the
 * number of transactions are capped.
 */
final class PaxmlReader
{
    /** The versions published at https://www.paxml.se/api/schema_versions. */
    public const array VERSIONS = ['1.0', '2.0', '2.1', '2.2', '2.3'];

    public const int MAX_BYTES = 10 * 1024 * 1024;

    public const int MAX_LINES = 50_000;

    private const int MAX_TEXT = 255;

    public function read(string $contents): PayrollFile
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new UnreadablePayrollFile(__('Filen är större än 10 MB.'));
        }

        $previous = libxml_use_internal_errors(true);

        try {
            return $this->parse($contents);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function parse(string $contents): PayrollFile
    {
        $reader = new XMLReader;

        if ($contents === '' || ! $reader->XML($contents, null, LIBXML_NONET)) {
            throw new UnreadablePayrollFile(__('Filen går inte att läsa som PAXml.'));
        }

        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
        $reader->setParserProperty(XMLReader::LOADDTD, false);

        $version = null;
        $program = null;
        $lines = [];
        $ignored = [];
        $section = null;
        $sawRoot = false;
        $advance = true;

        while (true) {
            if ($advance && ! $reader->read()) {
                break;
            }

            $advance = true;

            if ($reader->nodeType === XMLReader::DOC_TYPE) {
                throw new UnreadablePayrollFile(__('Filen innehåller en DOCTYPE och läses inte, av säkerhetsskäl.'));
            }

            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->depth === 0) {
                if ($reader->localName !== 'paxml') {
                    throw new UnreadablePayrollFile(__('Filen är inte en PAXml-fil: den ska börja med elementet paxml.'));
                }

                $sawRoot = true;

                continue;
            }

            if ($reader->depth === 1) {
                $section = $reader->localName;

                if ($section === 'header') {
                    $header = self::children($this->expand($reader));
                    $version = $header['version'] ?? null;
                    $program = $header['programnamn'] ?? null;
                    $section = null;
                    $advance = false;

                    if (! $reader->next()) {
                        break;
                    }
                }

                continue;
            }

            if ($reader->depth !== 2) {
                continue;
            }

            $line = match ([$section, $reader->localName]) {
                ['lonetransaktioner', 'lonetrans'] => $this->salary($this->expand($reader), count($lines) + 1),
                ['tidtransaktioner', 'tidtrans'] => $this->time($this->expand($reader), count($lines) + 1),
                default => null,
            };

            if ($line !== null) {
                $lines[] = $line;

                if (count($lines) > self::MAX_LINES) {
                    throw new UnreadablePayrollFile(__('Filen har fler än :max transaktioner. Dela upp den.', ['max' => number_format(self::MAX_LINES, 0, ',', ' ')]));
                }
            } elseif ($section !== null && ! in_array($reader->localName, ['klar', 'attesterat'], true)) {
                $ignored[$section] = ($ignored[$section] ?? 0) + 1;
            }

            // The element is read (or left unread) as a whole; continue after it.
            $advance = false;

            if (! $reader->next()) {
                break;
            }
        }

        $errors = libxml_get_errors();
        $reader->close();

        if ($errors !== [] || ! $sawRoot) {
            throw new UnreadablePayrollFile(__('Filen går inte att läsa som PAXml. Kontrollera att hela filen kom med.'));
        }

        return new PayrollFile('PAXML', $version, $lines, $ignored, $program);
    }

    private function expand(XMLReader $reader): DOMNode
    {
        try {
            $node = $reader->expand();
        } catch (ErrorException) {
            // A transaction cut off or broken; libxml reports it as a warning.
            $node = false;
        }

        if ($node === false) {
            throw new UnreadablePayrollFile(__('Filen går inte att läsa som PAXml. Kontrollera att hela filen kom med.'));
        }

        return $node;
    }

    private function salary(DOMNode $node, int $lineNo): PayrollFileLine
    {
        $values = self::children($node);

        return new PayrollFileLine(
            lineNo: $lineNo,
            section: PayrollFileLine::SALARY,
            employeeRef: self::attribute($node, 'anstid'),
            personalNumber: self::attribute($node, 'persnr'),
            code: $values['lonart'] ?? $values['lonkod'] ?? null,
            description: $values['benamning'] ?? null,
            dateFrom: self::date($values['datum'] ?? $values['datumfrom'] ?? null),
            dateTo: self::date($values['datum'] ?? $values['datumtom'] ?? null),
            quantity: $values['antal'] ?? null,
            rate: $values['apris'] ?? null,
            amount: $values['belopp'] ?? null,
        );
    }

    private function time(DOMNode $node, int $lineNo): PayrollFileLine
    {
        $values = self::children($node);

        return new PayrollFileLine(
            lineNo: $lineNo,
            section: PayrollFileLine::TIME,
            employeeRef: self::attribute($node, 'anstid'),
            personalNumber: self::attribute($node, 'persnr'),
            code: $values['tidkod'] ?? null,
            description: null,
            dateFrom: self::date($values['datum'] ?? $values['datumfrom'] ?? null),
            dateTo: self::date($values['datum'] ?? $values['datumtom'] ?? null),
            quantity: $values['timmar'] ?? null,
            rate: null,
            amount: null,
        );
    }

    /**
     * The text of each child element by name, the first of each name.
     *
     * @return array<string, string>
     */
    private static function children(DOMNode $node): array
    {
        $values = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && ! isset($values[$child->localName ?? ''])) {
                $text = trim($child->textContent);

                if ($text !== '') {
                    $values[(string) $child->localName] = mb_substr($text, 0, self::MAX_TEXT);
                }
            }
        }

        return $values;
    }

    private static function attribute(DOMNode $node, string $name): ?string
    {
        if (! $node instanceof DOMElement || ! $node->hasAttribute($name)) {
            return null;
        }

        $value = trim($node->getAttribute($name));

        return $value === '' ? null : mb_substr($value, 0, self::MAX_TEXT);
    }

    /** The day of an xs:date or xs:dateTime as YYYY-MM-DD; null when absent, '' when it is not a date. */
    private static function date(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) !== 1) {
            return $value === null ? null : '';
        }

        return $match[1];
    }
}
