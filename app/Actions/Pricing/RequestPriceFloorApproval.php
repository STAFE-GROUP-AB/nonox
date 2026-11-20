<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceFloorApprovalStatus;
use App\Domain\Pricing\PriceFloorDocument;
use App\Domain\Pricing\PriceFloorFingerprint;
use App\Domain\Pricing\PriceFloorGuard;
use App\Models\Company;
use App\Models\PriceFloorApproval;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Asks for the approval of an invoice, quote or order whose manual prices are
 * under the company's lowest contribution margin (ADR-082 §9). The request is
 * for the version of the rows the document has now: an open request on an
 * earlier version is withdrawn, since that version no longer exists. Anyone
 * who may change the document may ask; deciding is another permission.
 */
class RequestPriceFloorApproval
{
    public function __invoke(User $user, Company $company, PriceFloorDocument $type, string $documentId): PriceFloorApproval
    {
        return CurrentCompany::run($company, fn (): PriceFloorApproval => DB::transaction(function () use ($user, $company, $type, $documentId): PriceFloorApproval {
            $document = $type->find($documentId);

            Gate::forUser($user)->authorize('update', $document);

            // Lock the document so a save cannot slip in between the check and the request.
            $document::query()->whereKey($document->id)->lockForUpdate()->first();
            $document->unsetRelation('rows');

            $state = PriceFloorGuard::state($company, $document);

            if (! $state->needed) {
                throw ValidationException::withMessages(['approval' => __('Inget pris på :what ligger under lägsta täckningsgrad, så inget godkännande behövs.', ['what' => $type->label()])]);
            }

            $fingerprint = PriceFloorFingerprint::of($document);

            $open = PriceFloorApproval::query()
                ->where('document_type', $type->value)
                ->where('document_id', $document->id)
                ->where('status', PriceFloorApprovalStatus::Pending->value)
                ->lockForUpdate()
                ->get();

            $same = $open->firstWhere('fingerprint', $fingerprint);

            if ($same instanceof PriceFloorApproval) {
                return $same;
            }

            foreach ($open as $earlier) {
                $earlier->forceFill(['status' => PriceFloorApprovalStatus::Withdrawn])->save();
            }

            $approval = PriceFloorApproval::create([
                'company_id' => $company->id,
                'document_type' => $type,
                'document_id' => $document->id,
                'fingerprint' => $fingerprint,
                'requested_by' => $user->id,
            ]);

            Audit::record('price_floor.requested', $approval, [
                'document_type' => $type->value,
                'document_id' => $document->id,
                'lines' => $state->lines,
            ], $company, $user);

            return $approval;
        }));
    }
}
