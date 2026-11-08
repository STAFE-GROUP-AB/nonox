<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\SigningRequest;
use Closure;
use InvalidArgumentException;

/**
 * The kinds of document that can be signed at a link, and how a signing
 * request finds its document again (ADR-046, ADR-075 §7). The quote is
 * registered by the core (AppServiceProvider), the agreement by CRM, and a
 * module adds its own type in its service provider.
 *
 * A type names the signing request column that holds the document's key:
 * the quote and the agreement have their own foreign keys
 * (sales_document_id, agreement_id); any later type uses the generic
 * signable_id.
 */
final class SignableTypes
{
    public const string GENERIC_COLUMN = 'signable_id';

    /** @var array<string, array{column: string, find: Closure(string, bool): ?Signable}> */
    private array $types = [];

    /**
     * @param  Closure(string, bool): ?Signable  $find  the document by key, locked for update when the flag is true; null when gone
     */
    public function register(string $type, string $column, Closure $find): void
    {
        if (strlen($type) > 10) {
            throw new InvalidArgumentException("Signing type {$type} is longer than ten characters.");
        }

        $this->types[$type] = ['column' => $column, 'find' => $find];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** The signing request column that holds the key for this type. */
    public function column(string $type): string
    {
        return $this->entry($type)['column'];
    }

    /** The request's document, inside its company, or null when it is gone or its type is not installed. */
    public function documentFor(SigningRequest $request, bool $lock = false): ?Signable
    {
        if (! $this->has($request->document_type)) {
            return null;
        }

        $key = $request->getAttribute($this->column($request->document_type));

        return $key === null ? null : ($this->entry($request->document_type)['find'])((string) $key, $lock);
    }

    /**
     * The attributes a new signing request stores for the document.
     *
     * @return array<string, string|int>
     */
    public function attributesFor(Signable $document): array
    {
        $column = $this->column($document->signingType());

        return [
            'document_type' => $document->signingType(),
            $column => $column === 'agreement_id' ? (int) $document->signingKey() : $document->signingKey(),
        ];
    }

    /** @return array{column: string, find: Closure(string, bool): ?Signable} */
    private function entry(string $type): array
    {
        return $this->types[$type] ?? throw new InvalidArgumentException("Signing type {$type} is not registered.");
    }
}
