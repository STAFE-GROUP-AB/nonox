<?php

declare(strict_types=1);

namespace App\Support\Search;

use Carbon\CarbonImmutable;

/**
 * What the user typed in the spotlight, understood: the free text that is
 * left, and the filters found in it ("faktura", "> 10000 kr", "oktober",
 * "obetald"). Each filter is shown as a chip under the field, and each chip
 * knows the words it came from so that removing it removes them.
 */
final readonly class SearchQuery
{
    public const string UNPAID = 'unpaid';

    public const string OVERDUE = 'overdue';

    public const string PAID = 'paid';

    public const string DRAFT = 'draft';

    public const string POSTED = 'posted';

    public const string TO_APPROVE = 'to-approve';

    /**
     * @param  list<string>  $types  provider keys the user asked for ("faktura" → invoices); empty for all
     * @param  ?string  $typeWord  the word that asked for the types, for pages and actions
     * @param  ?int  $amountMin  öre, inclusive
     * @param  ?int  $amountMax  öre, inclusive
     * @param  ?int  $number  the text is a whole number: a voucher, invoice, customer or account number
     * @param  ?int  $impliedAmount  the bare number read as kronor, in öre (lower rank than a number match)
     * @param  ?string  $series  voucher series letter in "A47" or "A 47"
     * @param  list<array{kind: string, label: string, token: string}>  $chips
     */
    public function __construct(
        public string $raw,
        public string $text = '',
        public array $types = [],
        public ?string $typeWord = null,
        public ?int $amountMin = null,
        public ?int $amountMax = null,
        public ?CarbonImmutable $dateFrom = null,
        public ?CarbonImmutable $dateTo = null,
        public ?string $status = null,
        public ?int $number = null,
        public ?int $impliedAmount = null,
        public ?string $series = null,
        public array $chips = [],
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->raw) === '';
    }

    public function hasAmount(): bool
    {
        return $this->amountMin !== null || $this->amountMax !== null;
    }

    public function hasDate(): bool
    {
        return $this->dateFrom !== null;
    }

    public function hasStatus(): bool
    {
        return $this->status !== null;
    }

    /** Whether the user limited the search to some kinds of records and this is not one of them. */
    public function excludes(string $type): bool
    {
        return $this->types !== [] && ! in_array($type, $this->types, true);
    }

    /** @return list<string> the words of the free text, at most five */
    public function terms(): array
    {
        $words = preg_split('/\s+/u', trim($this->text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($words, 0, 5);
    }

    /** The text pages and actions match on: the free text, or the type word when that is all there is. */
    public function navigationText(): string
    {
        if ($this->hasAmount() || $this->hasDate() || $this->hasStatus()) {
            return '';
        }

        if ($this->types !== [] && trim($this->text) === '') {
            return (string) $this->typeWord;
        }

        return $this->types === [] ? $this->text : '';
    }

    /** A digit string the user typed, such as a number, OCR or the end of an organisation number. */
    public function digits(): ?string
    {
        $digits = preg_replace('/[\s-]/u', '', $this->text);

        return is_string($digits) && $digits !== '' && ctype_digit($digits) ? $digits : null;
    }
}
