<?php

declare(strict_types=1);

namespace App\Support\Ixbrl;

use App\Domain\AnnualReport\PrintedFigures;
use InvalidArgumentException;

/**
 * Writes the facts of an Inline XBRL 1.1 document (ADR-058 §2) and
 * collects the contexts and units they need for the ix:header.
 *
 * Amounts arrive in öre and are tagged in whole kronor, the way the report
 * prints them (PrintedFigures::round, like the PDF), with decimals="0". The printed minus
 * sign stands outside the tag; the tag holds the absolute value, and
 * sign="-" marks a fact whose value is negative in the direction of the
 * concept's balance: a cost the report prints as -2 000 on a debit concept
 * is the fact 2 000, a reversal it prints as 500 on that concept is -500.
 * A concept without a balance takes the value as printed.
 *
 * TODO(provider-verify): Bolagsverket's tillämpningsanvisning 1.8 on the
 * sign of costs and of the "Minskning av …" concepts without a balance,
 * on decimals (whole kronor is what the PDF prints), and on which
 * transformation registry it accepts (this writer uses TR4, 2020-02-12).
 * The taxonomier.se tagging examples were not read for this stage.
 *
 * A fact whose concept is null is not tagged: the label is kept and the
 * caller refuses the document (missing()).
 */
final class InlineXbrlWriter
{
    public const string TRANSFORMATIONS = 'http://www.xbrl.org/inlineXBRL/transformation/2020-02-12';

    /** @var array<string, array{0: 'instant'|'duration', 1: ?string, 2: string}> */
    private array $contexts = [];

    /** @var array<string, string> unit id => measure */
    private array $units = [];

    /** @var list<string> */
    private array $hidden = [];

    /** @var list<string> */
    private array $missing = [];

    /** @var array<string, true> */
    private array $used = [];

    private int $sequence = 0;

    /**
     * @param  array<string, array{0: 'instant'|'duration', 1: 'debit'|'credit'|null, 2: string}>  $concepts
     */
    public function __construct(
        private readonly string $scheme,
        private readonly string $identifier,
        private readonly array $concepts,
    ) {}

    /**
     * An amount as the report prints it, tagged. $orientation is the
     * direction in which the report prints the amount as positive: 'credit'
     * for the income statement, equity and liabilities, 'debit' for assets.
     *
     * @param  'debit'|'credit'  $orientation
     */
    public function money(?string $concept, string $label, int $ore, string $orientation, Period $period): string
    {
        $kronor = intdiv(PrintedFigures::round($ore), 100);
        $printed = self::kronor(abs($kronor));
        $minus = $kronor < 0 ? '-' : '';

        if ($concept === null) {
            $this->missing[] = $label;

            return $minus.$printed;
        }

        [$periodType, $balance, $kind] = $this->meta($concept, 'monetary');
        $value = $balance === null || $balance === $orientation ? $kronor : -$kronor;

        return $minus.$this->nonFraction($concept, $this->context($periodType, $period), $this->unit('SEK', 'iso4217:SEK'), $printed, $value < 0, 'decimals="0" format="ixt:num-comma-decimal"');
    }

    /** A percentage printed with one decimal and tagged as a ratio (45,3 → 0.453). */
    public function percent(?string $concept, string $label, ?float $percent, Period $period): string
    {
        if ($percent === null) {
            return '–';
        }

        $printed = number_format(abs($percent), 1, ',', '');
        $minus = $percent < 0 ? '-' : '';

        if ($concept === null) {
            $this->missing[] = $label;

            return $minus.$printed;
        }

        [$periodType] = $this->meta($concept, 'pure');

        return $minus.$this->nonFraction($concept, $this->context($periodType, $period), $this->unit('procent', 'xbrli:pure'), $printed, $percent < 0, 'decimals="3" scale="-2" format="ixt:num-comma-decimal"');
    }

    /**
     * A count printed as a whole number (medelantalet anställda).
     *
     * TODO(provider-verify): the unit Bolagsverket expects for
     * MedelantaletAnstallda (decimalItemType); xbrli:pure until then.
     */
    public function count(?string $concept, string $label, int $count, Period $period): string
    {
        $printed = (string) abs($count);
        $minus = $count < 0 ? '-' : '';

        if ($concept === null) {
            $this->missing[] = $label;

            return $minus.$printed;
        }

        [$periodType] = $this->meta($concept, 'decimal');

        return $minus.$this->nonFraction($concept, $this->context($periodType, $period), $this->unit('antal', 'xbrli:pure'), $printed, $count < 0, 'decimals="0"');
    }

    /** Text already escaped as XHTML, tagged as one fact. */
    public function text(?string $concept, string $label, string $xhtml, Period $period): string
    {
        if ($concept === null) {
            $this->missing[] = $label;

            return $xhtml;
        }

        [$periodType] = $this->meta($concept, null);

        return $this->nonNumeric($concept, $this->context($periodType, $period), $xhtml);
    }

    /** A date in ISO form (yyyy-mm-dd), printed and tagged as it is. */
    public function date(?string $concept, string $label, string $date, Period $period): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException("Not an ISO date: '{$date}'.");
        }

        return $this->text($concept, $label, $date, $period);
    }

    /** A hidden fact with a value-list member (an extensible enumeration). */
    public function hiddenEnumeration(string $concept, string $member, Period $period): void
    {
        [$periodType] = $this->meta($concept, 'enum');
        $this->hidden[] = $this->nonNumeric($concept, $this->context($periodType, $period), e($member));
    }

    /** A hidden amount in whole kronor, already in the direction of the concept's balance. */
    public function hiddenMoney(string $concept, int $kronor, Period $period): void
    {
        [$periodType] = $this->meta($concept, 'monetary');
        $this->hidden[] = $this->nonFraction($concept, $this->context($periodType, $period), $this->unit('SEK', 'iso4217:SEK'), (string) abs($kronor), $kronor < 0, 'decimals="0"');
    }

    /**
     * The lines that could not be tagged, in the order they were printed.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_unique($this->missing));
    }

    /** @return list<string> */
    public function usedConcepts(): array
    {
        return array_keys($this->used);
    }

    /**
     * The ix:header: hidden facts, the schema references, the contexts and
     * the units used by the facts written so far.
     *
     * @param  list<string>  $schemaRefs
     */
    public function header(array $schemaRefs): string
    {
        $out = '<ix:header>';

        if ($this->hidden !== []) {
            $out .= '<ix:hidden>'.implode('', $this->hidden).'</ix:hidden>';
        }

        $out .= '<ix:references>';

        foreach ($schemaRefs as $href) {
            $out .= '<link:schemaRef xlink:type="simple" xlink:href="'.e($href).'"/>';
        }

        $out .= '</ix:references><ix:resources>';

        foreach ($this->contexts as $id => [$type, $start, $end]) {
            $period = $type === 'instant'
                ? '<xbrli:instant>'.$end.'</xbrli:instant>'
                : '<xbrli:startDate>'.$start.'</xbrli:startDate><xbrli:endDate>'.$end.'</xbrli:endDate>';
            $out .= '<xbrli:context id="'.$id.'"><xbrli:entity><xbrli:identifier scheme="'.e($this->scheme).'">'.e($this->identifier).'</xbrli:identifier></xbrli:entity><xbrli:period>'.$period.'</xbrli:period></xbrli:context>';
        }

        foreach ($this->units as $id => $measure) {
            $out .= '<xbrli:unit id="'.$id.'"><xbrli:measure>'.$measure.'</xbrli:measure></xbrli:unit>';
        }

        return $out.'</ix:resources></ix:header>';
    }

    /**
     * Text without the characters XML 1.0 cannot carry (control characters
     * other than tab, line feed and carriage return, and non-characters), for
     * everything a person typed.
     */
    public static function xmlText(string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /** Kronor printed the way the PDF prints them: no decimals, a no-break space between thousands. */
    public static function kronor(int $kronor): string
    {
        return number_format($kronor, 0, ',', "\u{a0}");
    }

    /**
     * @return array{0: 'instant'|'duration', 1: 'debit'|'credit'|null, 2: string}
     */
    private function meta(string $concept, ?string $kind): array
    {
        $meta = $this->concepts[$concept] ?? throw new InvalidArgumentException("Concept '{$concept}' is not in the map.");

        if ($kind !== null && $meta[2] !== $kind) {
            throw new InvalidArgumentException("Concept '{$concept}' is {$meta[2]}, not {$kind}.");
        }

        $this->used[$concept] = true;

        return $meta;
    }

    private function context(string $periodType, Period $period): string
    {
        [$type, $start, $end] = $period->for($periodType);
        $id = $type === 'instant' ? "i{$end}" : "d{$start}_{$end}";
        $this->contexts[$id] = [$type, $start, $end];

        return $id;
    }

    private function unit(string $id, string $measure): string
    {
        $this->units[$id] = $measure;

        return $id;
    }

    private function nonFraction(string $concept, string $context, string $unit, string $printed, bool $negative, string $attributes): string
    {
        $sign = $negative ? ' sign="-"' : '';

        return '<ix:nonFraction name="'.$concept.'" contextRef="'.$context.'" unitRef="'.$unit.'" id="'.$this->id().'" '.$attributes.$sign.'>'.$printed.'</ix:nonFraction>';
    }

    private function nonNumeric(string $concept, string $context, string $xhtml): string
    {
        return '<ix:nonNumeric name="'.$concept.'" contextRef="'.$context.'" id="'.$this->id().'">'.$xhtml.'</ix:nonNumeric>';
    }

    private function id(): string
    {
        return 'fakta'.(++$this->sequence);
    }
}
