<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invoicing;

use App\Models\RecurringInvoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * An agreement's page. Route model binding goes through the company scope,
 * so another company's agreement answers 404.
 */
class AgreementController
{
    public function __invoke(RecurringInvoice $agreement): View
    {
        Gate::authorize('view', $agreement);

        return view('invoicing.agreements.show', ['agreement' => $agreement]);
    }
}
