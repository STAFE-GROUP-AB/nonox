<?php

declare(strict_types=1);

namespace App\Modules\Portal\Actions;

use App\Modules\Portal\Domain\EventType;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Support\PortalContext;
use Illuminate\Http\Request;

/**
 * Writes a portal event for the contact in context (ADR-075 §8): ids and
 * the kind only; the IP address and browser on SIGNED_IN only.
 */
final class RecordPortalEvent
{
    public function __construct(private readonly PortalContext $context) {}

    public function __invoke(EventType $event, ?string $subjectType = null, ?string $subjectId = null, ?Request $request = null): void
    {
        $contact = $this->context->contact();

        PortalEvent::query()->create([
            'company_id' => $contact->company_id,
            'customer_id' => $contact->customer_id,
            'contact_id' => $contact->id,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip' => $event === EventType::SignedIn ? $request?->ip() : null,
            'user_agent' => $event === EventType::SignedIn && $request !== null ? mb_substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }
}
