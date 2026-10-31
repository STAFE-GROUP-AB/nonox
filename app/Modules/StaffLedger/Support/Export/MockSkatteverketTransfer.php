<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support\Export;

use App\Models\Company;

/**
 * Stand-in until Skatteverket publishes how the ledger is transferred
 * (TODO(SPEC)). Sends nothing and says so; the XML file is downloaded
 * instead.
 */
final class MockSkatteverketTransfer implements SkatteverketTransfer
{
    /** @var list<array{company: int, bytes: int, format: string}> what would have been sent, for tests */
    public array $sent = [];

    public function isAvailable(): bool
    {
        return false;
    }

    public function send(Company $company, string $xml, string $formatVersion): array
    {
        $this->sent[] = ['company' => $company->id, 'bytes' => strlen($xml), 'format' => $formatVersion];

        return [
            'ok' => false,
            'reference' => null,
            'message' => __('Skatteverket tar ännu inte emot personalliggare elektroniskt. Ladda ner XML-filen och lämna den till handläggaren.'),
        ];
    }
}
