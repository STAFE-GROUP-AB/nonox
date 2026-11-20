<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PriceFloorApproval;
use App\Models\SalesDocument;
use Illuminate\Validation\ValidationException;

/**
 * Holds an invoice, quote or order whose manual prices are under the
 * company's lowest contribution margin until someone with
 * prices:approve-below-floor has approved that version of its rows
 * (ADR-082 §9, decision 12). Applies only while the company has a floor and
 * requires approval for it, which is off by default; to prices a person
 * typed (source MANUAL), since the others come from a list or agreement that
 * was set on purpose, to a discount typed on top of one, and to rows written
 * without an article; to a document in another currency (whose margin cannot
 * be checked) for its typed prices; and never to a credit invoice. Without a floor it costs nothing: no query is made.
 */
final class PriceFloorGuard
{
    public static function applies(Company $company, Invoice|SalesDocument $document): bool
    {
        if (! PriceFloor::of($company)->requiresApproval()) {
            return false;
        }

        return ! ($document instanceof Invoice && $document->isCredit());
    }

    /** As ensureCleared(), loading the company of the document. */
    public static function ensureClearedFor(Invoice|SalesDocument $document): void
    {
        self::ensureCleared(Company::query()->findOrFail($document->company_id), $document);
    }

    public static function state(Company $company, Invoice|SalesDocument $document): PriceFloorState
    {
        $floor = PriceFloor::of($company);

        if (! self::applies($company, $document)) {
            return PriceFloorState::notNeeded($floor);
        }

        $margin = DocumentMargin::ofDocument($company, $document);

        if (! $margin->needsApproval()) {
            return PriceFloorState::notNeeded($floor);
        }

        $approval = PriceFloorApproval::query()
            ->where('document_type', PriceFloorDocument::of($document)->value)
            ->where('document_id', $document->id)
            ->where('fingerprint', PriceFloorFingerprint::of($document))
            ->where('status', '!=', PriceFloorApprovalStatus::Withdrawn->value)
            ->orderByDesc('id')
            ->first();

        return new PriceFloorState(true, array_map(fn (int $index): int => $index + 1, $margin->approvalRows()), $approval, $floor);
    }

    /**
     * Refuses to send or issue a document whose prices under the floor are
     * not approved in the version it has now. The message names the lines and
     * the floor, never a cost.
     *
     * @throws ValidationException
     */
    public static function ensureCleared(Company $company, Invoice|SalesDocument $document): void
    {
        $state = self::state($company, $document);

        if ($state->cleared()) {
            return;
        }

        $what = PriceFloorDocument::of($document)->label();

        throw ValidationException::withMessages([$document instanceof Invoice ? 'invoice' : 'document' => __('Priset på rad :lines ligger under företagets lägsta täckningsgrad (:floor) och måste godkännas av någon med behörighet innan :what skickas eller skapas. Be om godkännande på sidan för :what.', [
            'lines' => implode(', ', $state->lines),
            'floor' => $state->floor->label(),
            'what' => $what,
        ])]);
    }
}
