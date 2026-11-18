<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Approval\ApprovalRequired;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DraftApprovals;
use App\Domain\Approval\DraftApprovalStatus;
use App\Domain\Approval\SubjectFacts;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Posts a voucher a person entered (the voucher editor, the API, the inbox)
 * through PostVoucher, after the company's own MANUAL_VOUCHER policy has had
 * its say (ADR-081 E21). PostVoucher itself is not changed.
 *
 * While a policy applies to the draft as it is now, the draft is only posted
 * once that exact version is approved (DraftApprovals); until then this
 * refuses with ApprovalRequired and the draft stays a draft. Nothing is
 * numbered before posting, so the series stay gapless whatever happens to a
 * draft that waits. With no policy of the company's own (the default, owner
 * decision 19) it posts exactly as PostVoucher does.
 *
 * Lock order: the voucher first, as in PostVoucher, which locks it again in
 * the same transaction; the approval requests last.
 */
class PostManualVoucher
{
    public function __construct(private readonly PostVoucher $postVoucher) {}

    public function __invoke(User $user, Voucher $voucher): Voucher
    {
        Gate::forUser($user)->authorize('post', $voucher);

        return CurrentCompany::run($voucher->company_id, fn (): Voucher => DB::transaction(function () use ($user, $voucher): Voucher {
            /** @var Voucher $locked */
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VoucherStatus::Draft || ! DraftApprovals::isManualVoucher($locked)
                || ! DraftApprovals::mayBeHeld(ApprovalSubjectType::ManualVoucher, $locked->company_id, $locked->id)) {
                return ($this->postVoucher)($user, $locked);
            }

            $subjectId = DraftApprovals::voucherSubjectId($locked);
            $status = DraftApprovals::status(ApprovalSubjectType::ManualVoucher, $locked->company_id, $subjectId, fn (): SubjectFacts => SubjectFacts::forManualVoucher($locked));

            if (! $status->allowsPosting()) {
                throw ApprovalRequired::forVoucher($status, $subjectId);
            }

            $posted = ($this->postVoucher)($user, $locked);

            if ($status === DraftApprovalStatus::Approved) {
                $request = ApprovalRequest::query()
                    ->where('subject_type', ApprovalSubjectType::ManualVoucher->value)
                    ->where('subject_id', $subjectId)
                    ->where('status', DraftApprovalStatus::Approved->value)
                    ->orderByDesc('id')
                    ->firstOrFail();

                Audit::record('voucher.posted_after_approval', $posted, ['reference' => $posted->reference(), 'approval_request_id' => $request->id], $posted->company_id, $user);
            }

            // Earlier versions of the draft that still waited are gone with it.
            DraftApprovals::withdrawOtherVersions(ApprovalSubjectType::ManualVoucher, $locked->company_id, $locked->id, $subjectId, $user);

            return $posted;
        }));
    }
}
