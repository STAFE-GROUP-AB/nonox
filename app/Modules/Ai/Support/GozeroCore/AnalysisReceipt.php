<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support\GozeroCore;

/**
 * What GoZero Core reported about the last analysis in this request: its job
 * id, the units charged and the model's confidence, kept for the audit log
 * and for feedback.
 */
final class AnalysisReceipt
{
    public ?string $jobId = null;

    public int $units = 0;

    public ?float $confidence = null;
}
