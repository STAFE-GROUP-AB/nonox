<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\Privacy\DataSubjectExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Registerutdrag as JSON files (GDPR art. 15 and 20): the user's own
 * account, and, for those who manage them, one customer, supplier or
 * employee. Every export is written to the audit log.
 */
class DataSubjectExportController
{
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        Audit::record('privacy.exported', $user, ['subject' => 'user'], null, $user);

        return $this->download(DataSubjectExport::user($user), 'mina-uppgifter');
    }

    public function customer(Request $request, Customer $customer): JsonResponse
    {
        Gate::authorize('update', $customer);
        $this->log($request, $customer, 'customer');

        return $this->download(DataSubjectExport::customer($customer), 'registerutdrag-kund-'.$customer->number);
    }

    public function supplier(Request $request, Supplier $supplier): JsonResponse
    {
        Gate::authorize('update', $supplier);
        $this->log($request, $supplier, 'supplier');

        return $this->download(DataSubjectExport::supplier($supplier), 'registerutdrag-leverantor-'.$supplier->number);
    }

    public function employee(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('viewSensitive', $employee);
        $this->log($request, $employee, 'employee');

        return $this->download(DataSubjectExport::employee($employee), 'registerutdrag-anstalld-'.$employee->employee_number);
    }

    private function log(Request $request, Customer|Supplier|Employee $subject, string $kind): void
    {
        /** @var User $user */
        $user = $request->user();

        Audit::record('privacy.exported', $subject, ['subject' => $kind], Company::query()->find($subject->company_id), $user);
    }

    /** @param array<string, mixed> $data */
    private function download(array $data, string $name): JsonResponse
    {
        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="'.Str::slug($name).'.json"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
