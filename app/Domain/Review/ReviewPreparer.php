<?php

declare(strict_types=1);

namespace App\Domain\Review;

/** Who prepared a queue item (ADR-067 §2). An approval request is always prepared by a person. */
enum ReviewPreparer: string
{
    case Ai = 'AI';
    case Template = 'TEMPLATE';
    case Rule = 'RULE';
    case Sie = 'SIE';
    case Peppol = 'PEPPOL';
    case Assistant = 'ASSISTANT';
    case Person = 'PERSON';
}
