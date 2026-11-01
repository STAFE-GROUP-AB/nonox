<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Expense;
use App\Support\Files\InlineFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The receipt of an outlay, for payroll staff. Another company's outlay
 * answers 404.
 */
class ExpenseReceiptController
{
    public function __invoke(Request $request, Expense $expense): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->findOrFail($expense->company_id);

        abort_unless($user->hasTeamPermission($company, 'payroll:view') || $user->hasTeamPermission($company, 'payroll:run'), 404);
        abort_unless($expense->receipt_path !== null && Storage::disk(AttachToVoucher::DISK)->exists($expense->receipt_path), 404);

        return Storage::disk(AttachToVoucher::DISK)->response($expense->receipt_path, $expense->receipt_name, InlineFile::headers((string) $expense->receipt_mime));
    }
}
