<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Models\VoucherSeries;
use Illuminate\View\View;

class VoucherSeriesController
{
    public function __invoke(): View
    {
        return view('accounting.voucher-series', [
            'series' => VoucherSeries::query()->orderBy('code')->get(),
        ]);
    }
}
