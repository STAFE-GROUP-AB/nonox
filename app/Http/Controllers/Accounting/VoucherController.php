<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Accounting\AccountBalances;
use App\Models\CostCenter;
use App\Models\FixedAssetAddition;
use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherRow;
use App\Support\Files\InlineFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Voucher pages. Route model binding goes through the company scope, so
 * another company's voucher answers 404 (AGENTS.md §13.2).
 */
class VoucherController
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('viewAny', [Voucher::class, $user->currentCompany()]);

        return view('accounting.vouchers.index');
    }

    public function create(Request $request): View
    {
        $copyOf = $request->filled('kopia') && Str::isUuid((string) $request->query('kopia'))
            ? Voucher::query()->findOrFail((string) $request->query('kopia'))
            : null;

        return view('accounting.vouchers.edit', ['voucher' => null, 'copyOf' => $copyOf]);
    }

    public function show(Voucher $voucher): View
    {
        Gate::authorize('view', $voucher);

        $voucher->load(['rows.account', 'rows.costCenter', 'rows.project', 'series', 'corrects.series', 'correctedBy.series', 'attachments']);

        return view('accounting.vouchers.show', [
            'voucher' => $voucher,
            'postedBy' => $voucher->posted_by ? User::query()->find($voucher->posted_by) : null,
            'acquiredAssets' => $voucher->isPosted() ? $voucher->acquiredAssets()->orderBy('number')->get(['id', 'number', 'name', 'acquisition_ore']) : collect(),
            'assetAdditions' => $voucher->isPosted() ? FixedAssetAddition::query()->where('voucher_id', $voucher->id)->with('asset:id,number,name')->orderBy('id')->get() : collect(),
            'balances' => $voucher->isPosted()
                ? AccountBalances::afterRows($voucher->fiscal_year_id, $voucher->rows)
                : AccountBalances::afterDraftRows($voucher->rows, AccountBalances::atDate($voucher->fiscal_year_id, $voucher->voucher_date)),
            'showCostCenters' => $voucher->rows->contains(fn (VoucherRow $row): bool => $row->cost_center_id !== null) || CostCenter::query()->exists(),
            'showProjects' => $voucher->rows->contains(fn (VoucherRow $row): bool => $row->project_id !== null) || Project::query()->exists(),
        ]);
    }

    /**
     * Shows an attachment inline. The type is the stored, validated MIME type,
     * nosniff stops the browser from guessing another one, and the file is
     * shown in a sandbox (InlineFile). Underlag of
     * another type, such as the original e-mail (.eml) and attachments the
     * inbox linked, is only ever a download.
     */
    public function attachment(Voucher $voucher, int $attachment): StreamedResponse
    {
        Gate::authorize('view', $voucher);

        $file = VoucherAttachment::query()->where('voucher_id', $voucher->id)->findOrFail($attachment);

        if (! array_key_exists($file->mime, AttachToVoucher::ALLOWED)) {
            return Storage::disk(AttachToVoucher::DISK)->download($file->file_path, $file->original_name, InlineFile::headers('application/octet-stream'));
        }

        return Storage::disk(AttachToVoucher::DISK)->response($file->file_path, $file->original_name, InlineFile::headers($file->mime), 'inline');
    }

    public function edit(Voucher $voucher): View
    {
        Gate::authorize('update', $voucher);

        return view('accounting.vouchers.edit', ['voucher' => $voucher, 'copyOf' => null]);
    }
}
