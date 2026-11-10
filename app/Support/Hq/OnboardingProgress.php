<?php

declare(strict_types=1);

namespace App\Support\Hq;

use App\Domain\Waitlist\WaitlistStatus;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How far each approved waiting-list entry has got (ADR-038 addendum), for
 * the progress list in /hq and for the follow-up mails. One query, whatever
 * the number of entries: every fact is a subquery of the list query.
 *
 * It reads across companies, from the person's own companies only: those the
 * approved user owns and that were created after the approval. Plain SQL
 * with no model scopes, since an entry exists before any company does and
 * belongs to none. Only presence and dates are read, never amounts, names or
 * the contents of anything.
 *
 *   signed_in_at        the latest sign-in after the approval
 *   company_created_at  when the first company of the person was created
 *   moved_in            a finished SIE import or register import, or a started Fortnox move
 *   invoice_sent        an invoice with a number, i.e. one that has been issued
 *   bookkept            a posted voucher
 *   owned_company_before  the person owned a company before the approval
 *   has_earlier_entry   an earlier approved entry of the same user: one set of mails per user
 */
final class OnboardingProgress
{
    private const string SIGN_IN = 'auth.signed_in';

    /** @return Builder<WaitlistEntry> */
    public static function query(): Builder
    {
        $companies = 'select t.id from teams t where t.user_id = w.approved_user_id and t.created_at >= w.decided_at';

        $inner = DB::table('waitlist_entries as w')
            ->where('w.status', WaitlistStatus::Approved->value)
            ->whereNotNull('w.approved_user_id')
            ->select('w.*')
            ->selectRaw('(select max(a.created_at) from audit_logs a where a.user_id = w.approved_user_id and a.action = ? and a.created_at >= w.decided_at) as signed_in_at', [self::SIGN_IN])
            ->selectRaw('(select min(t.created_at) from teams t where t.user_id = w.approved_user_id and t.created_at >= w.decided_at) as company_created_at')
            ->selectRaw('exists (select 1 from audit_logs a where a.user_id = w.approved_user_id and a.action = ? and a.created_at >= w.decided_at) as signed_in', [self::SIGN_IN])
            ->selectRaw("exists ({$companies}) as has_company")
            ->selectRaw("(exists (select 1 from sie_imports s where s.status = 'COMPLETED' and s.company_id in ({$companies}))"
                ." or exists (select 1 from register_imports r where r.status = 'COMPLETED' and r.company_id in ({$companies}))"
                ." or exists (select 1 from fortnox_connections f where f.company_id in ({$companies}))) as moved_in")
            ->selectRaw("exists (select 1 from vouchers v where v.status = 'POSTED' and v.company_id in ({$companies})) as bookkept")
            ->selectRaw('exists (select 1 from teams t where t.user_id = w.approved_user_id and t.created_at < w.decided_at) as owned_company_before')
            ->selectRaw("exists (select 1 from waitlist_entries o where o.approved_user_id = w.approved_user_id and o.status = 'APPROVED' and (o.decided_at < w.decided_at or (o.decided_at = w.decided_at and o.id < w.id))) as has_earlier_entry")
            ->selectRaw("exists (select 1 from invoices i where i.number is not null and i.company_id in ({$companies})) as invoice_sent");

        return WaitlistEntry::query()
            ->fromSub($inner, 'waitlist_entries')
            ->select('waitlist_entries.*')
            ->withCasts(['signed_in_at' => 'immutable_datetime', 'company_created_at' => 'immutable_datetime', 'moved_in' => 'boolean', 'invoice_sent' => 'boolean', 'bookkept' => 'boolean', 'owned_company_before' => 'boolean', 'has_earlier_entry' => 'boolean', 'signed_in' => 'boolean', 'has_company' => 'boolean']);
    }

    /** How many of the four steps are done, as SQL for ordering. */
    public const string SCORE_SQL = '(signed_in::int + has_company::int + moved_in::int + invoice_sent::int)';
}
