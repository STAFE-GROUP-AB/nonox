<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\StaffLedger\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Csv;
use App\Support\Privacy\DataSubjectExport;
use App\Support\Tenancy\ArchivedCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registerutdrag as JSON files (GDPR art. 15 and 20): the user's own
 * account, and, for those who manage them, one customer, supplier,
 * employee, person in the staff ledger or sender to the inbox. Every
 * export is written to the audit log.
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
        ArchivedCompany::reading(fn () => Gate::authorize('update', $customer));
        $this->log($request, $customer, 'customer');

        return $this->download(DataSubjectExport::customer($customer), 'registerutdrag-kund-'.$customer->number);
    }

    public function supplier(Request $request, Supplier $supplier): JsonResponse
    {
        ArchivedCompany::reading(fn () => Gate::authorize('update', $supplier));
        $this->log($request, $supplier, 'supplier');

        return $this->download(DataSubjectExport::supplier($supplier), 'registerutdrag-leverantor-'.$supplier->number);
    }

    public function employee(Request $request, Employee $employee): JsonResponse
    {
        ArchivedCompany::reading(fn () => Gate::authorize('viewSensitive', $employee));
        $this->log($request, $employee, 'employee');

        return $this->download(DataSubjectExport::employee($employee), 'registerutdrag-anstalld-'.$employee->employee_number);
    }

    /**
     * A person in the staff ledger, for those who may see full personal
     * numbers there. Archive permission: it works with the module off, as
     * long as the ledger is kept. Another company's person answers 404.
     */
    public function staffLedgerPerson(Request $request, Person $person): JsonResponse
    {
        ArchivedCompany::reading(fn () => Gate::authorize('viewSensitive', [Person::class, Company::query()->findOrFail($person->company_id)]));
        $this->log($request, $person, 'staff_ledger_person');

        return $this->download(DataSubjectExport::staffLedgerPerson($person), 'registerutdrag-personal-'.$person->id);
    }

    /**
     * Everything the current company's inbox received from one address, as
     * JSON or CSV (?format=csv). Only the current company's messages are
     * searched. The audit entry holds a keyed hash of the address, not the
     * address, so the append-only log does not keep it for years.
     */
    public function inboxSender(Request $request): JsonResponse|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $user->currentCompany();

        abort_unless($company instanceof Company && ArchivedCompany::reading(fn (): bool => $user->hasTeamPermission($company, 'inbox:manage')), 403);

        /** @var array{avsandare: string, format?: string} $input */
        $input = $request->validate([
            'avsandare' => ['required', 'email', 'max:255'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $email = mb_strtolower(trim($input['avsandare']));
        $messages = DataSubjectExport::inboxMessages($email);

        Audit::record('privacy.exported', null, [
            'subject' => 'inbox_sender',
            'sender_hash' => hash_hmac('sha256', 'inbox-sender:'.$email, (string) config('app.key')),
            'messages' => count($messages),
        ], $company, $user);

        if (($input['format'] ?? 'json') === 'csv') {
            return $this->csv($messages, 'registerutdrag-inkorg');
        }

        return $this->download(DataSubjectExport::inboxSender($email), 'registerutdrag-inkorg');
    }

    private function log(Request $request, Customer|Supplier|Employee|Person $subject, string $kind): void
    {
        /** @var User $user */
        $user = $request->user();

        Audit::record('privacy.exported', $subject, ['subject' => $kind], Company::query()->find($subject->company_id), $user);
    }

    /** @param list<array<string, mixed>> $messages */
    private function csv(array $messages, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($messages): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['mottaget', 'avsandare', 'avsandarnamn', 'amne', 'status', 'dokumenttyp', 'bilagor'], ';', '"', '');

            foreach ($messages as $message) {
                /** @var list<array{filnamn: string, typ: string, storlek_byte: int, blockerad: bool}> $attachments */
                $attachments = $message['bilagor'];
                $files = implode(' | ', array_map(fn (array $file): string => "{$file['filnamn']} ({$file['typ']}, {$file['storlek_byte']} byte)", $attachments));

                fputcsv($out, Csv::row([...array_diff_key($message, ['bilagor' => true]), 'bilagor' => $files]), ';', '"', '');
            }

            fclose($out);
        }, Str::slug($name).'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
