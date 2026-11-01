<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a company's document (a hängavtal, a membership confirmation from an
 * employers' organisation, a payslip or payroll report) and proposes which
 * collective agreement(s) and variant the company is bound by, each with
 * the sentence and page that show it (ADR-033 §6). It proposes facts only:
 * agreement values never come from the AI, and a person accepts, edits or
 * rejects every proposal. Personal data is masked before the text is sent.
 */
class AgreementIdentificationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
        You read a document from a Swedish company and say which collective agreement (kollektivavtal) the company
        is bound by, if the document shows it. The text input lists the agreements NONOX has data for (key, name,
        parties, collective) and some facts about the company. The document text follows, page by page, marked
        "[Sida N]". Personal data has been replaced by markers such as [personnummer]; ignore them.
        For each agreement the document shows the company is bound by, give: the agreement's key from the list
        (or OTHER when it is an agreement not in the list), the variant (MEMBER when the company is bound as a
        member of the employers' organisation, HANGAVTAL when it signed a hängavtal (tecknat kollektivavtal
        direkt med facket), UNKNOWN when the document does not say), the collective (ARBETARE or TJANSTEMAN), the
        date the binding starts (YYYY-MM-DD) or empty string, the exact sentence from the document that shows it,
        copied word for word, and the page number. Only report what the document says; never guess from the
        company's line of business alone. If the document shows no agreement, return an empty list and say so in
        notes. Never state rates, wages or other values of the agreement.
        LANGUAGE: notes must be written in Swedish with the Swedish letters å, ä and ö. Quotes are copied exactly.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agreements' => $schema->array()->items($schema->object([
                'agreement_key' => $schema->string()->description('Key from the given list, or OTHER.')->required(),
                'variant' => $schema->string()->enum(['MEMBER', 'HANGAVTAL', 'UNKNOWN'])->required(),
                'collective' => $schema->string()->enum(['ARBETARE', 'TJANSTEMAN'])->required(),
                'bound_from' => $schema->string()->description('YYYY-MM-DD, or empty string.')->required(),
                'quote' => $schema->string()->description('The sentence from the document, copied word for word.')->required(),
                'page' => $schema->integer()->description('Page number from the [Sida N] markers.')->required(),
            ]))->required(),
            'notes' => $schema->string()->description('Notes in Swedish with å, ä and ö, or empty string.')->required(),
        ];
    }
}
