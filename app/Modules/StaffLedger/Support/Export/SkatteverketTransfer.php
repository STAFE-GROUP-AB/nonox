<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support\Export;

use App\Models\Company;

/**
 * Electronic transfer of the staff ledger to Skatteverket at a control visit
 * or on request (from 1 July 2027 under prop. 2025/26:282).
 *
 * TODO(SPEC): Skatteverket has not published an API for this. The container
 * binds MockSkatteverketTransfer until a real driver exists; the result says
 * so and nothing leaves the server.
 */
interface SkatteverketTransfer
{
    public function isAvailable(): bool;

    /**
     * @return array{ok: bool, reference: ?string, message: string}
     */
    public function send(Company $company, string $xml, string $formatVersion): array;
}
