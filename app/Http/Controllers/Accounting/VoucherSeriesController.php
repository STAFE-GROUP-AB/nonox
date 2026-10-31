<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Models\Company;
use App\Models\User;
use App\Models\VoucherSeries;
use App\Support\Tenancy\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VoucherSeriesController
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();
        abort_unless(StaffAccess::allows($user, $company), 403);

        return view('accounting.voucher-series', [
            'series' => VoucherSeries::query()->orderBy('code')->get(),
        ]);
    }
}
