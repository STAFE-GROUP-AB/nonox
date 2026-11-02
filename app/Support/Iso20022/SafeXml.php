<?php

declare(strict_types=1);

namespace App\Support\Iso20022;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Reads an ISO 20022 file from a bank without trusting it: no network, no
 * DOCTYPE (so no external or expanding entities), and elements found by
 * local name so every version of a message is read alike.
 */
final class SafeXml
{
    private function __construct(public readonly DOMXPath $xpath) {}

    /**
     * @throws InvalidArgumentException when the file is not an ISO 20022 document
     */
    public static function load(string $xml): self
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // A bank file never needs a DOCTYPE; refusing one rules out entity expansion and external entities.
        if (! $loaded || $document->doctype !== null || $document->documentElement?->localName !== 'Document') {
            throw new InvalidArgumentException('Not an ISO 20022 document.');
        }

        return new self(new DOMXPath($document));
    }

    /** The message element directly under Document, such as CstmrPmtStsRpt. */
    public function messageName(): ?string
    {
        foreach ($this->nodes('/*[local-name()="Document"]/*', null) as $node) {
            return $node->localName;
        }

        return null;
    }

    /**
     * A path of local names, "GrpHdr/MsgId", relative to the context node,
     * or from the top ("Document/…") when there is no context.
     */
    public static function path(string $path, bool $fromTop = false): string
    {
        $steps = array_filter(explode('/', $path), fn (string $step): bool => $step !== '');

        return ($fromTop ? '/' : '').implode('/', array_map(fn (string $step): string => "*[local-name()=\"{$step}\"]", $steps));
    }

    public function text(string $path, ?DOMElement $context = null): ?string
    {
        $node = $this->all($path, $context)[0] ?? null;
        $value = $node === null ? '' : trim($node->textContent);

        return $value === '' ? null : $value;
    }

    /** @return list<DOMElement> */
    public function all(string $path, ?DOMElement $context = null): array
    {
        return $this->nodes(self::path($path, $context === null), $context);
    }

    public function first(string $path, ?DOMElement $context = null): ?DOMElement
    {
        return $this->all($path, $context)[0] ?? null;
    }

    /** An amount in öre (hundredths of the currency), or null when absent. */
    public function amount(string $path, ?DOMElement $context = null): ?int
    {
        $value = $this->text($path, $context);

        if ($value === null) {
            return null;
        }

        try {
            return BigDecimal::of($value)->multipliedBy(100)->toScale(0)->toInt();
        } catch (MathException) {
            throw new InvalidArgumentException("Could not read the amount \"{$value}\".");
        }
    }

    public function currency(string $path, ?DOMElement $context = null): ?string
    {
        $node = $this->first($path, $context);
        $currency = $node?->getAttribute('Ccy');

        return $currency === null || $currency === '' ? null : strtoupper(substr($currency, 0, 3));
    }

    /** @return list<DOMElement> */
    private function nodes(string $query, ?DOMElement $context): array
    {
        $nodes = [];

        foreach ($this->xpath->query($query, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
