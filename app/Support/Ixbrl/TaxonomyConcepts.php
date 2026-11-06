<?php

declare(strict_types=1);

namespace App\Support\Ixbrl;

use App\Domain\AnnualReport\K2Taxonomy;
use DOMDocument;
use DOMElement;

/**
 * The concepts of an unpacked taxonomy package from taxonomier.se, read
 * from the schemas K2Taxonomy uses, to check the map against the real
 * taxonomy (ADR-058 §4). The package is never part of this repository:
 * the operator sets services.bolagsverket.taxonomy_dir
 * (BOLAGSVERKET_TAXONOMY_DIR) to a package they downloaded.
 */
final class TaxonomyConcepts
{
    /**
     * @param  array<string, array{periodType: ?string, balance: ?string, type: string, abstract: bool}>  $elements  by prefixed name
     * @param  list<string>  $problems  problems found while reading
     */
    private function __construct(
        private readonly array $elements,
        private readonly array $problems,
        private readonly string $directory,
    ) {}

    public static function fromDirectory(string $directory): self
    {
        $directory = rtrim($directory, '/');
        $elements = [];
        $problems = [];

        foreach (K2Taxonomy::SCHEMAS as $prefix => $path) {
            $file = "{$directory}/{$path}";

            if (! is_file($file)) {
                $problems[] = __('Taxonomin saknar filen :file.', ['file' => $path]);

                continue;
            }

            $document = new DOMDocument;

            if (! @$document->load($file, LIBXML_NONET)) {
                $problems[] = __('Taxonomins fil :file går inte att läsa.', ['file' => $path]);

                continue;
            }

            $namespace = $document->documentElement?->getAttribute('targetNamespace');

            if ($namespace !== K2Taxonomy::NAMESPACES[$prefix]) {
                $problems[] = __('Taxonomins fil :file har namnrymden :namespace.', ['file' => $path, 'namespace' => (string) $namespace]);

                continue;
            }

            foreach ($document->getElementsByTagNameNS('http://www.w3.org/2001/XMLSchema', 'element') as $element) {
                /** @var DOMElement $element */
                $name = $element->getAttribute('name');

                if ($name === '') {
                    continue;
                }

                $elements["{$prefix}:{$name}"] = [
                    'periodType' => $element->getAttributeNS('http://www.xbrl.org/2003/instance', 'periodType') ?: null,
                    'balance' => $element->getAttributeNS('http://www.xbrl.org/2003/instance', 'balance') ?: null,
                    'type' => $element->getAttribute('type'),
                    'abstract' => $element->getAttribute('abstract') === 'true',
                ];
            }
        }

        return new self($elements, $problems, $directory);
    }

    /**
     * What does not agree with the taxonomy: a missing entry point, a
     * concept that does not exist, is abstract, or has another period type,
     * balance or type than the map says; a value-list member that does not
     * exist. Empty when everything agrees.
     *
     * @param  list<string>  $concepts
     * @param  list<string>  $members
     * @param  list<string>  $entryPoints
     * @return list<string>
     */
    public function problems(array $concepts, array $members = [], array $entryPoints = []): array
    {
        $problems = $this->problems;

        foreach ($entryPoints as $url) {
            $path = preg_replace('#^https?://#', '', $url);

            if (! is_file("{$this->directory}/{$path}")) {
                $problems[] = __('Taxonomin saknar ingångspunkten :url.', ['url' => $url]);
            }
        }

        foreach ($concepts as $concept) {
            $element = $this->elements[$concept] ?? null;

            if ($element === null) {
                $problems[] = __('Begreppet :concept finns inte i taxonomin.', ['concept' => $concept]);

                continue;
            }

            [$periodType, $balance, $kind] = K2Taxonomy::concept($concept);

            if ($element['abstract'] || $element['periodType'] !== $periodType || $element['balance'] !== $balance || ! self::typeIs($element['type'], $kind)) {
                $problems[] = __('Begreppet :concept stämmer inte med taxonomin (:type, :period, :balance).', [
                    'concept' => $concept,
                    'type' => $element['type'],
                    'period' => (string) $element['periodType'],
                    'balance' => $element['balance'] ?? '–',
                ]);
            }
        }

        foreach ($members as $member) {
            if (! isset($this->elements[$member])) {
                $problems[] = __('Värdet :member finns inte i taxonomin.', ['member' => $member]);
            }
        }

        return $problems;
    }

    private static function typeIs(string $type, string $kind): bool
    {
        return match ($kind) {
            'monetary' => $type === 'xbrli:monetaryItemType',
            'pure' => $type === 'xbrli:pureItemType',
            'decimal' => $type === 'xbrli:decimalItemType',
            'date' => $type === 'xbrli:dateItemType',
            'enum' => $type === 'enum:enumerationItemType',
            // A string concept, or one restricted to fixed texts in its own schema.
            'string' => $type === 'xbrli:stringItemType' || str_starts_with($type, 'se-comp-base:'),
            default => false,
        };
    }
}
