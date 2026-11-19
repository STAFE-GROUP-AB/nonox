<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\CompletionLinks;
use App\Domain\Signing\CompletionMails;
use App\Domain\Signing\Signable;
use App\Models\Company;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * What the sender can do with the download links of a completed envelope
 * (ADR-046 S13): close them all ("Stäng länkarna"), or send one recipient a
 * new link, which closes that recipient's old one. Gated like withdrawing
 * an envelope (SendForSigning::authorizeAct): the document's own sending
 * rights, and for a confidential or restricted envelope its rule. The audit
 * entries carry ids and counts only, never an address.
 */
final class ManageDownloadLinks
{
    public function close(User $user, Company $company, SigningEnvelope $envelope): int
    {
        $this->authorize($user, $envelope);

        return DB::transaction(fn (): int => CurrentCompany::run($company, function () use ($user, $company, $envelope): int {
            $closed = CompletionLinks::close($envelope);
            Audit::record('signing.download_links_closed', $envelope, ['envelope_id' => $envelope->id, 'links' => $closed], $company, $user);

            return $closed;
        }));
    }

    /** "Skicka ny länk": a new link to the same recipient, the old one closed; mailed after the commit. */
    public function resend(User $user, Company $company, SigningEnvelope $envelope, SigningDownloadLink $link): void
    {
        abort_unless($link->envelope_id === $envelope->id && $link->company_id === $company->id, 404);
        $this->authorize($user, $envelope);

        if ($company->isTestCompany()) {
            return;
        }

        MailQuota::take($company, 'documents');

        DB::transaction(function () use ($user, $company, $envelope, $link): void {
            CurrentCompany::run($company, function () use ($user, $company, $envelope, $link): void {
                $request = $link->signing_request_id === null ? null : SigningRequest::query()->find($link->signing_request_id);
                CompletionLinks::close($envelope, $link);
                $token = CompletionLinks::issue($envelope, $link->kind, $link->email, $link->language, $request);
                $fresh = CompletionLinks::find($token);
                abort_unless($fresh instanceof SigningDownloadLink, 500);

                Audit::record('signing.download_link_resent', $envelope, ['envelope_id' => $envelope->id, 'old_link_id' => $link->id, 'link_id' => $fresh->id, 'kind' => $link->kind], $company, $user);
                Mail::to($link->email)->queue(CompletionMails::forLink($fresh, $token)->afterCommit());
            });
        });
    }

    private function authorize(User $user, SigningEnvelope $envelope): void
    {
        $document = CurrentCompany::run($envelope->company_id, fn (): ?Signable => SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('step')->orderBy('id')->first()?->document());
        abort_unless($document instanceof Signable, 404);
        SendForSigning::authorizeAct($document, $user, $envelope);
    }
}
