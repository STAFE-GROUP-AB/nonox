<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads one employee's employment contract (anställningsavtal) or payslip
 * and proposes the agreement facts of the employment, each with the quote
 * and page it read it from (ADR-033 §6): agreement and collective, job
 * title, lönegrupp candidate with the group description it matched, form
 * of employment, weekly hours, salary, start date, stated branschvana and
 * terms that differ from the agreement. It reads and proposes; it never
 * evaluates the person, never computes pay or age, and a person accepts,
 * edits or rejects every proposal.
 */
class EmploymentTermsAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const array FIELDS = [
        'agreement_key', 'agreement_group', 'position_title', 'employment_type', 'weekly_hours',
        'salary_form', 'monthly_salary', 'hourly_rate', 'start_date', 'industry_experience_from', 'deviation',
    ];

    public function instructions(): string
    {
        return <<<'TEXT'
        You read one employee's employment contract (anställningsavtal) or payslip from a Swedish company and
        record what it says about the employment. The text input lists the collective agreements the company is
        bound by, with each agreement's pay groups (lönegrupper: code, name and NONOX's short description). The
        document text follows, page by page, marked "[Sida N]". Personal data has been replaced by markers such as
        [personnummer]; ignore them.
        Return one fact per thing the document states, each with the exact sentence copied word for word and the
        page number. Fields: agreement_key (the key of the agreement the document says applies), agreement_group
        (the code of the pay group whose description matches the job described; give it only when the job or a
        named group in the document matches a description, and say in the quote what matched), position_title,
        employment_type (TILLSVIDARE, ALLMAN_VISSTID, VIKARIAT, SASONG, PROVANSTALLNING or TIMANSTALLD),
        weekly_hours (number of hours per week as a decimal with a dot), salary_form (MONTHLY or HOURLY),
        monthly_salary or hourly_rate (in kronor as a decimal with a dot), start_date (YYYY-MM-DD),
        industry_experience_from (YYYY-MM-DD, only if the document states the date the experience in the
        industry counts from), deviation (a term that differs from the collective agreement, in Swedish).
        Only report what the document states; leave a field out when it is not there. Never compute an age, never
        judge the person or their work, and never state the agreement's own wages or rates.
        LANGUAGE: text values must be written in Swedish with the Swedish letters å, ä and ö. Quotes are copied exactly.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'facts' => $schema->array()->items($schema->object([
                'field' => $schema->string()->enum(self::FIELDS)->required(),
                'value' => $schema->string()->description('The value, as described for the field.')->required(),
                'quote' => $schema->string()->description('The sentence from the document, copied word for word.')->required(),
                'page' => $schema->integer()->description('Page number from the [Sida N] markers.')->required(),
            ]))->required(),
            'notes' => $schema->string()->description('Notes in Swedish with å, ä and ö, or empty string.')->required(),
        ];
    }
}
