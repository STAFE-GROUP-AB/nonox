<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\ImportedPayslip;
use App\Modules\Payroll\PayrollModule;
use App\Support\Files\InlineFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A payslip from the earlier payroll system, for payroll staff, the archive
 * and the employee it belongs to. Anyone else gets 404.
 */
class ImportedPayslipController
{
    public function __invoke(Request $request, ImportedPayslip $payslip): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->findOrFail($payslip->company_id);
        $own = Employee::query()->whereKey($payslip->employee_id)->value('user_id') === $user->id
            && $user->hasTeamPermission($company, 'self:payslips')
            && (bool) (app(ModuleManager::class)->settings(PayrollModule::KEY, $company)['employee_self_service'] ?? true);

        abort_unless($own || $user->hasTeamPermission($company, 'payroll:view') || $user->hasTeamPermission($company, 'payroll:archive-view'), 404);
        abort_unless(Storage::disk(AttachToVoucher::DISK)->exists($payslip->file_path), 404);

        return Storage::disk(AttachToVoucher::DISK)->response($payslip->file_path, $payslip->original_name, InlineFile::headers('application/pdf'));
    }
}
