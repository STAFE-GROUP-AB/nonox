<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\MailingLink;
use App\Modules\Crm\Models\MailingRecipient;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\MailingPause;
use App\Modules\Crm\Support\MailingTokens;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * What happens after a mailing is sent (ADR-048), without anyone signing in.
 *
 * - unsubscribe (the recipient's personal token): the person is marked
 *   unsubscribed from mailings and the address goes on the suppression
 *   list (reason MARKETING), so no later mailing reaches it even if the
 *   person is erased and added again. This is the objection to direct
 *   marketing (GDPR art. 21(3)); other contact is still allowed. A stub
 *   (address and person already pruned) still works through the kept
 *   HMAC of the address.
 * - click (a link that names no one): the link's and the mailing's click
 *   counts; the redirect goes to the URL stored for that link.
 * - delivery events from Mailgun's webhook: delivered, a permanent failure
 *   (a bounce: the person's address gets no more mailings until it
 *   changes) and a spam complaint (an unsubscribe, and counted). Bounces
 *   and complaints may pause the company's mailings (MailingPause).
 *
 * The audit log gets ids only.
 */
final class MailingResponses
{
    /** The recipient behind a token, read across companies (the link carries no company). */
    public static function recipient(string $token): ?MailingRecipient
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token) !== 1) {
            return null;
        }

        // A public link names no company: the token's hash finds the row, which then decides the company.
        return CompanyScope::bypass(fn (): ?MailingRecipient => MailingRecipient::query()->where('token_hash', MailingTokens::hash($token))->first());
    }

    public function unsubscribe(MailingRecipient $recipient, string $via = 'link'): void
    {
        CurrentCompany::run($recipient->company_id, function () use ($recipient, $via): void {
            DB::transaction(function () use ($recipient, $via): void {
                $recipient = MailingRecipient::query()->lockForUpdate()->findOrFail($recipient->id);
                $person = $recipient->person_id === null ? null : Person::query()->find($recipient->person_id);
                $email = $recipient->email ?? $person?->email;

                if ($email !== null && $email !== '') {
                    SuppressionList::suppressMarketing($email);
                } elseif ($recipient->email_hash !== null) {
                    SuppressionList::suppressMarketingHash($recipient->email_hash);
                }

                if ($person instanceof Person && $person->marketing_opt_out_at === null) {
                    $person->forceFill(['marketing_opt_out_at' => now()])->save();
                }

                if ($recipient->unsubscribed_at === null) {
                    $recipient->forceFill(['unsubscribed_at' => now()])->save();
                    Mailing::query()->whereKey($recipient->mailing_id)->increment('unsubscribed_count');
                    Audit::record('crm.unsubscribed', $recipient, ['mailing_id' => $recipient->mailing_id, 'person_id' => $recipient->person_id, 'via' => $via], $recipient->company_id);
                }
            });
        });
    }

    /** Counts a click on a link and returns where to go, or null for a link NONOX did not make. */
    public function click(int $linkId, string $signature): ?string
    {
        if (! MailingTokens::validSignature($linkId, $signature)) {
            return null;
        }

        // The link names no company: its id, proven by the signature, finds the row.
        $link = CompanyScope::bypass(fn (): ?MailingLink => MailingLink::query()->find($linkId));

        if (! $link instanceof MailingLink || preg_match('/^https?:\/\//i', $link->url) !== 1) {
            return null;
        }

        CurrentCompany::run($link->company_id, function () use ($link): void {
            MailingLink::query()->whereKey($link->id)->increment('clicks');
            Mailing::query()->whereKey($link->mailing_id)->increment('clicks');
        });

        return $link->url;
    }

    /** A delivery event from Mailgun for one recipient: delivered, failed (permanent) or complained. */
    public function event(MailingRecipient $recipient, string $event, string $severity, string $address): void
    {
        // The event is for the address this recipient was sent to, while it is still kept.
        if ($recipient->email !== null && mb_strtolower(trim($address)) !== $recipient->email) {
            return;
        }

        if ($event === 'complained') {
            $this->unsubscribe($recipient, 'complaint');
            $first = CurrentCompany::run($recipient->company_id, fn (): bool => MailingRecipient::query()->whereKey($recipient->id)->whereNull('complained_at')->update(['complained_at' => now()]) === 1);

            if ($first) {
                CurrentCompany::run($recipient->company_id, fn () => Mailing::query()->whereKey($recipient->mailing_id)->increment('complaint_count'));
                $this->checkPause($recipient->company_id);
            }

            return;
        }

        $bounced = CurrentCompany::run($recipient->company_id, function () use ($recipient, $event, $severity): bool {
            if ($event === 'delivered' && MailingRecipient::query()->whereKey($recipient->id)->whereNull('delivered_at')->update(['delivered_at' => now()]) === 1) {
                Mailing::query()->whereKey($recipient->mailing_id)->increment('delivered_count');

                return false;
            }

            if ($event !== 'failed' || $severity !== 'permanent') {
                return false;
            }

            $first = MailingRecipient::query()->whereKey($recipient->id)->where('status', '<>', MailingRecipient::BOUNCED)->update(['status' => MailingRecipient::BOUNCED, 'updated_at' => now()]) === 1;

            if ($first) {
                Mailing::query()->whereKey($recipient->mailing_id)->increment('bounced_count');
            }

            $person = $recipient->person_id === null ? null : Person::query()->find($recipient->person_id);

            if ($person instanceof Person && $recipient->email !== null && mb_strtolower((string) $person->email) === $recipient->email && $person->email_bounced_at === null) {
                $person->forceFill(['email_bounced_at' => now()])->save();
            }

            Audit::record('crm.mailing_bounced', $recipient, ['mailing_id' => $recipient->mailing_id, 'person_id' => $recipient->person_id], $recipient->company_id);

            return $first;
        });

        if ($bounced) {
            $this->checkPause($recipient->company_id);
        }
    }

    private function checkPause(int $companyId): void
    {
        $company = Company::query()->find($companyId);

        if ($company instanceof Company) {
            app(MailingPause::class)->check($company);
        }
    }
}
