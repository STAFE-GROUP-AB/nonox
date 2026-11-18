<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Domain\Accounting\VoucherSource;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Manual vouchers and credit invoices as approval subjects (ADR-081 E21).
 *
 * Both are drafts while they wait: a voucher gets its number in its series
 * only when PostVoucher posts it, and a credit invoice its invoice number
 * only when IssueInvoice issues it, so a draft that waits for approval, or
 * is rejected and never posted, leaves no gap. The approval is bound to the
 * draft's version (DraftFingerprint): the subject id is "{id}.{fingerprint}",
 * so saving a changed draft makes a new subject that needs its own approval.
 *
 * The default policy for both is "no approval" (owner decision 19): with no
 * policy of the company's own that applies, and no request on the version,
 * the draft is posted or issued as before and no row is written.
 *
 * Runs inside the company.
 */
final class DraftApprovals
{
    /** The subject id of a voucher draft as it is now: three queries. */
    public static function voucherSubjectId(Voucher $voucher): string
    {
        return DraftFingerprint::subjectId($voucher->id, DraftFingerprint::vouchers($voucher->company_id, [$voucher->id])[$voucher->id] ?? '');
    }

    /** The subject id of a credit invoice draft as it is now: two queries. */
    public static function invoiceSubjectId(Invoice $invoice): string
    {
        return DraftFingerprint::subjectId($invoice->id, DraftFingerprint::invoices($invoice->company_id, [$invoice->id])[$invoice->id] ?? '');
    }

    /** Whether a voucher is one a MANUAL_VOUCHER policy can hold: a draft entered by a person. */
    public static function isManualVoucher(Voucher $voucher): bool
    {
        return $voucher->source === VoucherSource::Manual && $voucher->corrects_voucher_id === null;
    }

    /**
     * Locks the voucher draft a subject id names, in the current company
     * (another company's id is not found), and checks that it still says
     * what the subject id says: a draft saved after the page was opened is
     * refused, so nobody approves a version they have not seen.
     */
    public static function lockVoucher(string $subjectId): Voucher
    {
        [$id] = DraftFingerprint::parse($subjectId) ?? throw new ModelNotFoundException;

        $voucher = Voucher::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if (! self::isManualVoucher($voucher)) {
            throw new ModelNotFoundException;
        }

        if (self::voucherSubjectId($voucher) !== $subjectId) {
            throw ValidationException::withMessages(['approve' => __('Verifikationen har ändrats sedan du öppnade sidan. Ladda om den och försök igen.')]);
        }

        return $voucher;
    }

    /** As lockVoucher, for a credit invoice draft. */
    public static function lockCreditInvoice(string $subjectId): Invoice
    {
        [$id] = DraftFingerprint::parse($subjectId) ?? throw new ModelNotFoundException;

        $invoice = Invoice::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if (! $invoice->isCredit()) {
            throw new ModelNotFoundException;
        }

        if (self::invoiceSubjectId($invoice) !== $subjectId) {
            throw ValidationException::withMessages(['approve' => __('Kreditfakturan har ändrats sedan du öppnade sidan. Ladda om den och försök igen.')]);
        }

        return $invoice;
    }

    /**
     * Whether an approval can hold the draft at all: the company has an
     * active policy for the type, or some version of the draft has a
     * request. False for every company that never added such a policy, so
     * posting and issuing cost one query more than before and nothing else.
     */
    public static function mayBeHeld(ApprovalSubjectType $type, int $companyId, string $draftId): bool
    {
        return ApprovalPolicy::query()->where('company_id', $companyId)->where('subject_type', $type->value)->where('is_active', true)->exists()
            || ApprovalRequest::query()->where('company_id', $companyId)->where('subject_type', $type->value)->where('subject_id', 'like', "{$draftId}.%")->exists();
    }

    /**
     * Where the draft's current version stands: approved, waiting (a
     * request is open or was rejected, or a policy applies) or free to post
     * as before. Two or three queries; the facts are read only when a policy
     * has to be tested.
     *
     * @param  \Closure(): SubjectFacts  $facts
     */
    public static function status(ApprovalSubjectType $type, int $companyId, string $subjectId, \Closure $facts): DraftApprovalStatus
    {
        $latest = ApprovalRequest::query()
            ->where('company_id', $companyId)
            ->where('subject_type', $type->value)
            ->where('subject_id', $subjectId)
            ->where('status', '!=', ApprovalRequestStatus::Withdrawn->value)
            ->orderByDesc('id')
            ->first();

        if ($latest instanceof ApprovalRequest) {
            return match ($latest->status) {
                ApprovalRequestStatus::Approved => DraftApprovalStatus::Approved,
                ApprovalRequestStatus::Rejected => DraftApprovalStatus::Rejected,
                default => DraftApprovalStatus::Pending,
            };
        }

        $policies = ApprovalPolicy::query()
            ->where('company_id', $companyId)
            ->where('subject_type', $type->value)
            ->where('is_active', true)
            ->with('steps')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($policies->isEmpty()) {
            return DraftApprovalStatus::NotRequired;
        }

        $subject = $facts();

        return $policies->contains(fn (ApprovalPolicy $policy): bool => PolicySnapshot::of($policy, $subject) instanceof PolicySnapshot)
            ? DraftApprovalStatus::Required
            : DraftApprovalStatus::NotRequired;
    }

    /**
     * Withdraws the open requests on the draft's other versions (or on all
     * of them when $keep is null): the draft has changed, been posted or
     * issued, or been deleted. Call it with the draft locked.
     */
    public static function withdrawOtherVersions(ApprovalSubjectType $type, int $companyId, string $draftId, ?string $keep, User $actor): void
    {
        $requests = ApprovalRequest::query()
            ->where('company_id', $companyId)
            ->where('subject_type', $type->value)
            ->where('subject_id', 'like', "{$draftId}.%")
            ->where('status', ApprovalRequestStatus::Pending->value)
            ->when($keep !== null, fn ($query) => $query->where('subject_id', '!=', $keep))
            ->lockForUpdate()
            ->get();

        foreach ($requests as $request) {
            $request->forceFill(['status' => ApprovalRequestStatus::Withdrawn, 'completed_at' => now()])->save();

            Audit::record('approval.withdrawn', $request, ['subject_type' => $type->value, 'subject_id' => $request->subject_id], $companyId, $actor);
        }
    }
}
