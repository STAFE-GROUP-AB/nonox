<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Agents;

use App\Modules\Agreements\Domain\LocalOverrides;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a company's local agreement (lokalt avtal) and proposes where it
 * deviates from the central collective agreement, as overrides in the
 * nonox.agreement/1 paths LocalOverrides allows (ADR-033 §6), each with the
 * sentence and page it read it from. The values it proposes are the local
 * agreement's own, read from the document; the central values come only
 * from the verified agreement data and are never asked of the AI. A person
 * with agreements:manage accepts, edits or rejects each proposal, and an
 * accepted override is used in a run only after a person with
 * agreements:verify has verified it.
 */
class LocalAgreementAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
        You read a local agreement (lokalt avtal) between a Swedish employer and a union, made under a central
        collective agreement. The text input lists the collective agreements the company is bound by, with the
        codes of each agreement (code, name, kind, NONOX's short description), and the kinds of deviation NONOX
        can record (path). The document text follows, page by page, marked "[Sida N]". Personal data has been
        replaced by markers such as [personnummer]; ignore them.
        Return one deviation per term in the document that replaces a term of the central agreement, each with:
        agreement_key (from the list), path (one of the listed paths; replace * with the code the term is about),
        and the value the document states in the field that fits: amount_kr (kronor as a decimal with a dot, per
        hour, per occasion or per day as the document says), percent (a percentage as a decimal with a dot),
        divisor (a number the monthly salary is divided by), weeks (a whole number of weeks), hours (hours as a
        decimal with a dot) or text (SALARY or NONE for a commission guarantee; a short description in Swedish for
        path "other"). Leave the other value fields as empty strings. Give a short description in Swedish of what
        the term changes, the exact sentence from the document copied word for word, and the page number.
        Only report what the document states. Never state the central agreement's own values, never compute a
        value, never judge a person.
        LANGUAGE: text values must be written in Swedish with the Swedish letters å, ä and ö. Quotes are copied exactly.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'deviations' => $schema->array()->items($schema->object([
                'agreement_key' => $schema->string()->description('Key of the agreement from the list.')->required(),
                'path' => $schema->string()->description('One of: '.implode(', ', array_keys(LocalOverrides::PATHS)).', with * replaced by a code.')->required(),
                'amount_kr' => $schema->string()->description('Kronor as a decimal with a dot, or empty string.')->required(),
                'percent' => $schema->string()->description('Percent as a decimal with a dot, or empty string.')->required(),
                'divisor' => $schema->string()->description('Divisor of the monthly salary, or empty string.')->required(),
                'weeks' => $schema->string()->description('Whole weeks, or empty string.')->required(),
                'hours' => $schema->string()->description('Hours as a decimal with a dot, or empty string.')->required(),
                'text' => $schema->string()->description('SALARY, NONE, or a Swedish description for path other; else empty string.')->required(),
                'description' => $schema->string()->description('What the term changes, in Swedish with å, ä and ö.')->required(),
                'quote' => $schema->string()->description('The sentence from the document, copied word for word.')->required(),
                'page' => $schema->integer()->description('Page number from the [Sida N] markers.')->required(),
            ]))->required(),
            'notes' => $schema->string()->description('Notes in Swedish with å, ä and ö, or empty string.')->required(),
        ];
    }
}
