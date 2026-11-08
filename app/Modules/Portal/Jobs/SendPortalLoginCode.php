<?php

declare(strict_types=1);

namespace App\Modules\Portal\Jobs;

use App\Actions\Auth\SendLoginCode;
use App\Models\LoginCode;
use App\Modules\Portal\Mail\PortalMail;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Support\PortalAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A portal sign-in code (ADR-075 §8), sent from the queue: the sign-in
 * form dispatches this job for every address it is given and answers the
 * same at once, so neither the answer nor its timing tells whether the
 * address has a portal. Only here is it decided: an address without an
 * ACTIVE contact (or, for an invitation, the invited address only) gets
 * nothing. Not defer(): that killed PHP-FPM on 2026-11-04.
 */
class SendPortalLoginCode implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  ?int  $invitedCompanyId  set when the code is for accepting an invitation of that company: the address was taken from the invitation, not typed
     */
    public function __construct(
        public string $email,
        public ?string $ip,
        public ?int $brandCompanyId = null,
        public ?int $invitedCompanyId = null,
    ) {}

    public function handle(SendLoginCode $send, PortalAccess $access): void
    {
        if ($this->invitedCompanyId === null && ! $access->hasAccess($this->email)) {
            return;
        }

        // The brand of the portal address the visitor came from, only when the person has a portal in that company.
        $identity = PortalIdentity::query()->where('email', PortalIdentity::normalise($this->email))->first();
        $branded = $this->brandCompanyId !== null && $identity instanceof PortalIdentity && $access->contactsFor($identity)->contains('company_id', $this->brandCompanyId);
        $companyId = $this->invitedCompanyId ?? ($branded ? $this->brandCompanyId : null);

        $send($this->email, $this->ip, null, LoginCode::PORTAL, fn (string $code): PortalMail => new PortalMail(PortalMail::CODE, $companyId, code: $code));
    }
}
