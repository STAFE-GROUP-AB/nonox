<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Events\CustomerAnonymised;
use App\Models\Company;
use App\Modules\Portal\Actions\ManagePortalContacts;
use App\Modules\Portal\Domain\ContactStatus;
use App\Modules\Portal\Domain\ThreadKind;
use App\Modules\Portal\Mail\PortalMail;
use App\Modules\Portal\Models\PortalAttachment;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\Models\PortalThread;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Removes portal data that has served its purpose (ADR-075 §12, GDPR art.
 * 5.1 e), for one company at a time and strictly inside the portal's own
 * tables:
 *
 * - an invited contact whose invitation was never used, 14 days after it
 *   ran out;
 * - an active contact with no sign-in for 12 months gets an e-mail; 30 days
 *   later, still without a sign-in, it is removed (decision O9);
 * - a disabled contact, 30 days after it was disabled;
 * - portal events after 24 months;
 * - a closed thread 24 months after it closed, unless it is a dispute or the
 *   company keeps it;
 * - an identity with no contacts left.
 *
 * TODO(legal-verify): L3 — the periods; L5 — whether questions about an
 * invoice are räkenskapsinformation to be kept seven years.
 */
final class PortalRetention
{
    public const int INACTIVE_MONTHS = 12;

    public const int NOTICE_DAYS = 30;

    public const int DISABLED_DAYS = 30;

    public const int CLOSED_THREAD_MONTHS = 24;

    /**
     * @return array{invited: int, notified: int, inactive: int, disabled: int, events: int, threads: int}
     */
    public function prune(Company $company, CarbonImmutable $now): array
    {
        return CurrentCompany::run($company, function () use ($company, $now): array {
            $counts = ['invited' => 0, 'notified' => 0, 'inactive' => 0, 'disabled' => 0, 'events' => 0, 'threads' => 0];

            $invited = PortalContact::query()->where('status', ContactStatus::Invited->value)
                ->whereDoesntHave('invitations', fn ($query) => $query->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', $now))
                ->where('invited_at', '<', $now->subDays(PortalInvitation::VALID_DAYS))
                ->get();
            $counts['invited'] = $this->remove($invited, 'EXPIRED_INVITATION');

            $disabled = PortalContact::query()->where('status', ContactStatus::Disabled->value)->where('disabled_at', '<', $now->subDays(self::DISABLED_DAYS))->get();
            $counts['disabled'] = $this->remove($disabled, 'DISABLED');

            $cutoff = $now->subMonthsNoOverflow(self::INACTIVE_MONTHS);
            $inactive = PortalContact::query()->where('status', ContactStatus::Active->value)
                ->whereRaw('coalesce(last_seen_at, accepted_at, created_at) < ?', [$cutoff]);

            $counts['inactive'] = $this->remove((clone $inactive)->whereNotNull('inactivity_notified_at')->where('inactivity_notified_at', '<', $now->subDays(self::NOTICE_DAYS))->get(), 'INACTIVE');

            $settings = PortalSettings::current();

            foreach ((clone $inactive)->whereNull('inactivity_notified_at')->with('identity')->get() as $contact) {
                $contact->forceFill(['inactivity_notified_at' => $now])->save();

                if ($contact->identity instanceof PortalIdentity && ! $company->isTestCompany()) {
                    Mail::to($contact->identity->email)->queue(new PortalMail(PortalMail::INACTIVITY, $company->id, route('portal.home', ['portalKey' => $settings->portal_key])));
                }

                $counts['notified']++;
            }

            $counts['events'] = PortalEvent::query()->where('created_at', '<', $now->subMonthsNoOverflow(PortalEvent::RETENTION_MONTHS))->delete();

            $threads = PortalThread::query()->where('status', PortalThread::CLOSED)->where('keep', false)->where('kind', '<>', ThreadKind::Dispute->value)
                ->where('closed_at', '<', $now->subMonthsNoOverflow(self::CLOSED_THREAD_MONTHS))->get();

            foreach ($threads as $thread) {
                $paths = PortalAttachment::query()->whereIn('message_id', $thread->messages()->select('id'))->pluck('path')->all();
                $thread->delete();
                DB::afterCommit(fn () => Storage::disk(PortalAttachment::DISK)->delete($paths));
                $counts['threads']++;
            }

            return $counts;
        });
    }

    /** A customer anonymised under GDPR: its contacts (and orphaned identities) go now. */
    public function customerAnonymised(CustomerAnonymised $event): void
    {
        CurrentCompany::run($event->companyId, function () use ($event): void {
            $this->remove(PortalContact::query()->where('customer_id', $event->customerId)->get(), 'CUSTOMER_ANONYMISED');
        });
    }

    /** @param  iterable<PortalContact>  $contacts */
    private function remove(iterable $contacts, string $reason): int
    {
        $count = 0;

        foreach ($contacts as $contact) {
            DB::transaction(fn () => ManagePortalContacts::delete($contact));
            Audit::record('customer_portal.contact_removed', null, ['contact_id' => $contact->id, 'customer_id' => $contact->customer_id, 'reason' => $reason], $contact->company_id);
            $count++;
        }

        return $count;
    }
}
