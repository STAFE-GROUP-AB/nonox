<?php

declare(strict_types=1);

namespace App\Livewire\Approval;

use App\Actions\Accounting\PostManualVoucher;
use App\Actions\Approval\ApproveDraft;
use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\SubmitForApproval;
use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalSubjects;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DecisionOutcome;
use App\Domain\Approval\DraftApprovals;
use App\Domain\Approval\DraftApprovalStatus;
use App\Domain\Approval\SubjectFacts;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The approval card of a manual voucher draft (on the voucher's page) or a
 * credit invoice draft (in the invoice editor) under the company's own
 * policy (ADR-081 E21): the steps and decisions, and Skicka till attest,
 * Godkänn, Avvisa and, for a voucher once approved, Bokför.
 *
 * The card is bound to the version of the draft it showed ($subjectId,
 * locked): a draft saved since is refused under its row lock, and the card
 * asks for the page to be reloaded. Shows nothing when no policy can hold
 * the draft, so a company without one sees no change.
 */
class DraftApprovalCard extends Component
{
    use ShowsToasts;

    #[Locked]
    public string $type;

    #[Locked]
    public string $draftId;

    /** The version shown, "{id}.{fingerprint}". */
    #[Locked]
    public string $subjectId = '';

    public string $rejectReason = '';

    public function mount(string $type, string $draftId): void
    {
        $subject = ApprovalSubjectType::from($type);
        abort_unless(in_array($subject, [ApprovalSubjectType::ManualVoucher, ApprovalSubjectType::CreditInvoice], true), 404);

        $this->type = $subject->value;
        $this->draftId = $draftId;
        $this->subjectId = $this->currentSubjectId();
    }

    /** The invoice editor saved the draft: the card follows the version the person now sees. */
    #[On('credit-draft-saved')]
    public function follow(): void
    {
        $this->subjectId = $this->currentSubjectId();
    }

    public function submit(SubmitForApproval $submit): void
    {
        $submit($this->user(), $this->company(), $this->subjectType(), $this->subjectId);

        $this->toast($this->subjectType() === ApprovalSubjectType::ManualVoucher
            ? __('Verifikationen är skickad till attest.')
            : __('Kreditfakturan är skickad till attest.'));
    }

    public function approve(ApproveDraft $approve): void
    {
        $outcome = $approve($this->user(), $this->company(), $this->subjectType(), $this->subjectId);

        $this->toast(match (true) {
            $outcome !== DecisionOutcome::Completed => __('Ditt godkännande är sparat. Underlaget väntar på nästa attest.'),
            $this->subjectType() === ApprovalSubjectType::ManualVoucher => __('Verifikationen är attesterad och kan bokföras.'),
            default => __('Kreditfakturan är attesterad och kan skapas.'),
        });
    }

    public function reject(RejectApproval $reject): void
    {
        $reject($this->user(), $this->company(), $this->subjectType(), $this->subjectId, $this->rejectReason);

        $this->rejectReason = '';
        $this->toast(__('Underlaget är avvisat och går tillbaka till den som förberedde det.'));
    }

    public function post(PostManualVoucher $post): void
    {
        abort_unless($this->subjectType() === ApprovalSubjectType::ManualVoucher, 404);

        $voucher = Voucher::query()->findOrFail($this->draftId);

        // Only the version the card showed is posted.
        if (DraftApprovals::voucherSubjectId($voucher) !== $this->subjectId) {
            $this->addError('approve', __('Verifikationen har ändrats sedan du öppnade sidan. Ladda om den och försök igen.'));

            return;
        }

        $posted = $post($this->user(), $voucher);

        Toast::flash(__(':reference är bokförd.', ['reference' => $posted->reference()]));
        $this->redirectRoute('accounting.vouchers.show', ['voucher' => $posted->id]);
    }

    public function render(ApprovalEngine $engine): View
    {
        $type = $this->subjectType();
        $draft = $this->draft();
        $company = $this->company();
        $empty = ['visible' => false];

        if ($draft === null || ! ApprovalSubjects::awaiting($draft) || ($draft instanceof Voucher && ! DraftApprovals::isManualVoucher($draft))
            || ! DraftApprovals::mayBeHeld($type, $company->id, $this->draftId)) {
            return view('livewire.approval.draft-approval-card', $empty);
        }

        $current = $this->currentSubjectId();

        if ($current !== $this->subjectId) {
            return view('livewire.approval.draft-approval-card', ['visible' => true, 'changed' => true]);
        }

        $facts = $draft instanceof Voucher ? SubjectFacts::forManualVoucher($draft) : SubjectFacts::forCreditInvoice($draft);

        $status = DraftApprovals::status($type, $company->id, $current, fn (): SubjectFacts => $facts);

        if ($status === DraftApprovalStatus::NotRequired) {
            return view('livewire.approval.draft-approval-card', $empty);
        }

        $history = $engine->histories($company->id, $type, [$current])->get($current, collect());
        $state = $engine->state(ApprovalContext::load($company), $this->user(), $facts, $history);
        $user = $this->user();
        $preparer = in_array($user->id, $facts->preparerIds, true) || $user->hasTeamPermission($company, ApprovalSubjects::preparePermission($type));
        $open = in_array($status, [DraftApprovalStatus::Required, DraftApprovalStatus::Pending], true);

        return view('livewire.approval.draft-approval-card', [
            'visible' => true,
            'changed' => false,
            'status' => $status,
            'approval' => $state,
            'isVoucher' => $type === ApprovalSubjectType::ManualVoucher,
            'memberNames' => $company->allUsers()->pluck('name', 'id')->all(),
            'canSubmit' => $status === DraftApprovalStatus::Required && $preparer,
            'canResubmit' => $status === DraftApprovalStatus::Rejected && $preparer,
            'canApprove' => $open && $state->canDecide(),
            'canReject' => $open && ($state->canDecide() || $user->hasTeamPermission($company, 'approvals:manage')),
            'canPost' => $status === DraftApprovalStatus::Approved && $type === ApprovalSubjectType::ManualVoucher && $user->can('post', $draft),
        ]);
    }

    /** As SubmitForApproval: sending a rejected version again. */
    public function resubmit(SubmitForApproval $submit): void
    {
        $this->submit($submit);
    }

    private function currentSubjectId(): string
    {
        $draft = $this->draft();

        return match (true) {
            $draft instanceof Voucher => DraftApprovals::voucherSubjectId($draft),
            $draft instanceof Invoice => DraftApprovals::invoiceSubjectId($draft),
            default => '',
        };
    }

    private function draft(): Voucher|Invoice|null
    {
        return $this->subjectType() === ApprovalSubjectType::ManualVoucher
            ? Voucher::query()->find($this->draftId)
            : Invoice::query()->where('kind', Invoice::CREDIT)->find($this->draftId);
    }

    private function subjectType(): ApprovalSubjectType
    {
        return ApprovalSubjectType::from($this->type);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
