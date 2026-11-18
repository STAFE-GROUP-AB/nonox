<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use Illuminate\Validation\ValidationException;

/**
 * A manual voucher or credit invoice draft that a policy of the company's own
 * holds until it is approved (ADR-081 E21). A validation error, so the API
 * answers 422 and a page shows the message; the editors catch it to send the
 * draft to approval instead.
 */
final class ApprovalRequired extends ValidationException
{
    public DraftApprovalStatus $approvalStatus = DraftApprovalStatus::Required;

    public string $subjectId = '';

    public static function forVoucher(DraftApprovalStatus $status, string $subjectId): self
    {
        $exception = self::withMessages(['voucher' => match ($status) {
            DraftApprovalStatus::Pending => __('Verifikationen väntar på attest och kan bokföras när den är attesterad.'),
            DraftApprovalStatus::Rejected => __('Verifikationen avvisades i attesten. Ändra den, eller skicka den till attest igen.'),
            default => __('Verifikationen ska attesteras innan den bokförs. Skicka den till attest.'),
        }]);
        $exception->approvalStatus = $status;
        $exception->subjectId = $subjectId;

        return $exception;
    }

    public static function forCreditInvoice(DraftApprovalStatus $status, string $subjectId): self
    {
        $exception = self::withMessages(['invoice' => match ($status) {
            DraftApprovalStatus::Pending => __('Kreditfakturan väntar på attest och kan skapas när den är attesterad.'),
            DraftApprovalStatus::Rejected => __('Kreditfakturan avvisades i attesten. Ändra den, eller skicka den till attest igen.'),
            default => __('Kreditfakturan ska attesteras innan den skapas. Skicka den till attest.'),
        }]);
        $exception->approvalStatus = $status;
        $exception->subjectId = $subjectId;

        return $exception;
    }
}
