<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A write to an archived company (ADR-024). The permission check refuses
 * every write first; this is the last line, for code that writes without
 * asking for a permission (scheduled commands, queued jobs, kiosks).
 */
final class CompanyIsArchived extends AccessDeniedHttpException
{
    public static function whileWriting(string $model): self
    {
        Log::warning('Refused a write to an archived company.', ['model' => $model]);

        return new self(__('Företaget är arkiverat och kan bara läsas.'));
    }
}
