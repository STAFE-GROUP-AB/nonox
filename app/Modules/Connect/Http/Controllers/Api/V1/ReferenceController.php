<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\VoucherSeries;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\VatCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The company and the reference data a voucher needs: chart of accounts,
 * VAT codes, fiscal years and voucher series (ADR-032). Read-only.
 */
final class ReferenceController
{
    public function company(): JsonResponse
    {
        $credential = ConnectAccess::current();
        abort_if($credential === null, 401);

        return CursorList::one([
            ...ApiPresenter::company($credential->company),
            'credential' => [
                'type' => $credential->kind,
                'name' => $credential->name,
                'scopes' => $credential->scopes,
            ],
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $query = Account::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return CursorList::respond($request, $query, fn (Account $account): array => ApiPresenter::account($account));
    }

    public function account(int $number): JsonResponse
    {
        return CursorList::one(ApiPresenter::account(Account::query()->where('number', $number)->firstOrFail()));
    }

    public function vatCodes(): JsonResponse
    {
        return new JsonResponse(['data' => VatCodes::all()], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function fiscalYears(Request $request): JsonResponse
    {
        return CursorList::respond($request, FiscalYear::query(), fn (FiscalYear $year): array => ApiPresenter::fiscalYear($year));
    }

    public function fiscalYear(FiscalYear $fiscalYear): JsonResponse
    {
        return CursorList::one(ApiPresenter::fiscalYear($fiscalYear));
    }

    public function voucherSeries(): JsonResponse
    {
        $series = VoucherSeries::query()->orderBy('code')->get();

        return new JsonResponse(['data' => $series->map(fn (VoucherSeries $one): array => ApiPresenter::series($one))->values()->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
