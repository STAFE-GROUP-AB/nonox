<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Models\Message;
use App\Modules\Planning\Models\MessageRecipient;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\SelfService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * An employee reads a schedule message, or confirms that they have read it,
 * on Min sida (ADR-037 gap 5). Only their own receipt: anyone else's throws
 * AuthorizationException (the page answers 404). The first time counts; a
 * receipt is not audited, since the audit log outlives the message's 12
 * months, and it is shown to the planner per message only.
 */
final class MarkMessage
{
    public function __construct(private readonly SelfService $self) {}

    public function read(User $user, Company $company, MessageRecipient $receipt): MessageRecipient
    {
        $this->own($user, $company, $receipt);

        if ($receipt->read_at === null) {
            $receipt->forceFill(['read_at' => CarbonImmutable::now(LocalTimestamp::ZONE)])->save();
        }

        return $receipt;
    }

    public function acknowledge(User $user, Company $company, MessageRecipient $receipt): MessageRecipient
    {
        $this->own($user, $company, $receipt);

        if (! (bool) Message::query()->whereKey($receipt->message_id)->value('requires_ack')) {
            throw ValidationException::withMessages(['message' => __('Meddelandet behöver ingen bekräftelse.')]);
        }

        $now = CarbonImmutable::now(LocalTimestamp::ZONE);
        $receipt->forceFill([
            'read_at' => $receipt->read_at ?? $now,
            'acknowledged_at' => $receipt->acknowledged_at ?? $now,
        ])->save();

        return $receipt;
    }

    private function own(User $user, Company $company, MessageRecipient $receipt): void
    {
        $employee = $this->self->employee($user, $company);

        if ($receipt->company_id !== $company->id || $receipt->employee_id !== $employee->id) {
            throw new AuthorizationException;
        }
    }
}
