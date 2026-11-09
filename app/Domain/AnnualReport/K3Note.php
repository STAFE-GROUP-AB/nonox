<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * One generated note of a K3 annual report (ADR-059, A.2.7): its key, the row
 * of the rule sheet's checklist S1-R8.1 it answers, its place in the order of
 * K3 8.2, the structured data the books gave, the generated Swedish text and,
 * when a person wrote their own wording, that text with the reason. The data
 * is never overridden; an override replaces the text only (spec S1
 * Behaviour 6). Open questions come in two kinds (Lead decision after the
 * S1-P9 review): `missing` are wording questions (how the stage of
 * completion is determined, why an effect cannot be estimated, an asset's
 * life), which a person's own text answers; `facts` are questions about the
 * books or the company's facts (a transition not booked, a policy not
 * chosen, an amount that does not agree with the ledger), which no override
 * ever closes. `blocking` is set when the note describes something the K3
 * document refuses (S1-P10).
 */
final readonly class K3Note
{
    /**
     * @param  list<string>  $rules  the provisions the note answers, e.g. "ÅRL 5 kap. 20 §"
     * @param  array<string, mixed>  $data
     * @param  list<string>  $generatedText  one paragraph per entry
     * @param  list<string>  $missing  wording questions, closed by an override
     * @param  list<string>  $facts  questions about the books or facts, never closed by an override
     */
    public function __construct(
        public string $key,
        public int $row,
        public string $section,
        public string $heading,
        public array $rules,
        public array $data,
        public array $generatedText,
        public int $number,
        public ?string $overrideText = null,
        public ?string $overrideReason = null,
        public bool $stale = false,
        public array $missing = [],
        public ?string $blocking = null,
        public array $facts = [],
    ) {}

    public function overridden(): bool
    {
        return $this->overrideText !== null;
    }

    /**
     * The text printed: the person's own wording when there is one (one
     * paragraph per block separated by a blank line), else the generated text.
     *
     * @return list<string>
     */
    public function text(): array
    {
        if ($this->overrideText === null) {
            return $this->generatedText;
        }

        $paragraphs = preg_split('/\R\s*\R/u', trim($this->overrideText)) ?: [];

        return array_values(array_filter(array_map('trim', $paragraphs), fn (string $paragraph): bool => $paragraph !== ''));
    }

    /**
     * The questions still open: the questions about the books and facts
     * always, the wording questions only until a person has written the note
     * in their own words.
     *
     * @return list<string>
     */
    public function openQuestions(): array
    {
        return $this->overridden() ? $this->facts : [...$this->facts, ...$this->missing];
    }

    /**
     * The hash of a generated text, stored with an override so a later change
     * in the books shows the override as out of date.
     *
     * @param  list<string>  $generatedText
     */
    public static function hash(array $generatedText): string
    {
        return hash('sha256', (string) json_encode($generatedText, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
