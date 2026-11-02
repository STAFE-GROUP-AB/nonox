<?php

declare(strict_types=1);

use App\Migration\Fortnox\FortnoxConnection;
use App\Migration\Fortnox\FortnoxImportedFile;
use App\Models\Account;
use App\Models\Accrual;
use App\Models\AccrualPosting;
use App\Models\AnnualReport;
use App\Models\ArchivePackage;
use App\Models\Article;
use App\Models\BankFileImport;
use App\Models\BankReconciliation;
use App\Models\BankTransaction;
use App\Models\ClientProfile;
use App\Models\ClosingCheck;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetProposal;
use App\Models\ImportedVoucher;
use App\Models\IncomeTaxReturn;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceReminder;
use App\Models\InvoiceRow;
use App\Models\MonthCloseCheck;
use App\Models\NumberSeries;
use App\Models\OpeningBalance;
use App\Models\Project;
use App\Models\RecentItem;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRow;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Models\SieImport;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentBatch;
use App\Models\SupplierPaymentBatchItem;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskRecurrence;
use App\Models\User;
use App\Models\VatNumberCheck;
use App\Models\VatReport;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherNumberCounter;
use App\Models\VoucherRow;
use App\Models\VoucherRowHistory;
use App\Models\VoucherSeries;
use App\Models\YearEndEntry;
use App\Modules\Agreements\Models\AgreementDocument;
use App\Modules\Agreements\Models\AgreementProposal;
use App\Modules\Agreements\Models\AgreementRuleVerification;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\Banking\Models\BankFeedBalance;
use App\Modules\Banking\Models\BankFeedTransaction;
use App\Modules\Connect\Models\ConnectApiKey;
use App\Modules\Connect\Models\ConnectGrant;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Connect\Models\IdempotencyRecord;
use App\Modules\Inbox\Models\AllowedSender;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMailbox;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\InboxTemplate;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Accumulator;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\ImportedPayslip;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollFileImport;
use App\Modules\Payroll\Models\PayrollFileImportLine;
use App\Modules\Payroll\Models\PayrollImportMapping;
use App\Modules\Payroll\Models\PayrollImportSource;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollMigration;
use App\Modules\Payroll\Models\PayrollRuleVerification;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollYearClosing;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\PensionPlan;
use App\Modules\Payroll\Models\RecurringInput;
use App\Modules\Payroll\Models\SalaryChange;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\Models\VacationLiabilitySnapshot;
use App\Modules\Payroll\Models\VacationOpeningBalance;
use App\Modules\Payroll\Models\VacationYearClosing;
use App\Modules\Payroll\Models\WorkSchedule;
use App\Modules\Planning\Models\Area as PlanningArea;
use App\Modules\Planning\Models\Attestation as PlanningAttestation;
use App\Modules\Planning\Models\Availability as PlanningAvailability;
use App\Modules\Planning\Models\EmployeeSkill as PlanningEmployeeSkill;
use App\Modules\Planning\Models\IcalToken as PlanningIcalToken;
use App\Modules\Planning\Models\Publication as PlanningPublication;
use App\Modules\Planning\Models\Shift as PlanningShift;
use App\Modules\Planning\Models\ShiftRequest as PlanningShiftRequest;
use App\Modules\Planning\Models\Skill as PlanningSkill;
use App\Modules\Planning\Models\Template as PlanningTemplate;
use App\Modules\Planning\Models\TemplateShift as PlanningTemplateShift;
use App\Modules\StaffLedger\Models\Device as StaffLedgerDevice;
use App\Modules\StaffLedger\Models\Entry as StaffLedgerEntry;
use App\Modules\StaffLedger\Models\Location as StaffLedgerLocation;
use App\Modules\StaffLedger\Models\Person as StaffLedgerPerson;
use App\Modules\StaffLedger\Models\TimeProposal as StaffLedgerTimeProposal;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\MissingCompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Tests\Tenancy\Fixtures\ListNotesForCompany;
use Tests\Tenancy\Fixtures\TenantNote;

/*
 * AGENTS.md §13.2: every company-scoped model is registered here with the
 * attributes needed to create one. The test at the bottom fails when a model
 * uses BelongsToCompany without being listed.
 */
const COMPANY_SCOPED_MODELS = [
    TenantNote::class => ['title' => 'Hemlig anteckning'],
    Account::class => ['number' => 1930, 'name' => 'Företagskonto', 'type' => 'ASSET'],
    FiscalYear::class => ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
    VoucherSeries::class => ['code' => 'A', 'name' => 'Manuell'],
    CostCenter::class => ['code' => '10', 'name' => 'Restaurang Malmö'],
    Project::class => ['code' => 'P1', 'name' => 'Ombyggnad kök'],
    // Records that need other records of the same company get a closure.
    Voucher::class => 'voucherAttributes',
    VoucherRow::class => 'voucherRowAttributes',
    VoucherNumberCounter::class => 'counterAttributes',
    VoucherAttachment::class => 'attachmentAttributes',
    OpeningBalance::class => 'openingBalanceAttributes',
    SieImport::class => 'sieImportAttributes',
    ImportedVoucher::class => 'importedVoucherAttributes',
    VoucherRowHistory::class => 'voucherRowHistoryAttributes',
    VatReport::class => 'vatReportAttributes',
    ArchivePackage::class => 'archivePackageAttributes',
    ClientProfile::class => 'clientProfileAttributes',
    FixedAsset::class => 'fixedAssetAttributes',
    Accrual::class => 'accrualAttributes',
    AnnualReport::class => 'annualReportAttributes',
    IncomeTaxReturn::class => 'incomeTaxReturnAttributes',
    ClosingCheck::class => 'closingCheckAttributes',
    YearEndEntry::class => 'yearEndEntryAttributes',
    BankReconciliation::class => 'bankReconciliationAttributes',
    VatNumberCheck::class => 'vatNumberCheckAttributes',
    FortnoxConnection::class => 'fortnoxConnectionAttributes',
    FortnoxImportedFile::class => ['file_id' => 'f-1', 'fortnox_year' => 1, 'voucher_series' => 'A', 'voucher_number' => 1],
    AccrualPosting::class => 'accrualPostingAttributes',
    FixedAssetDepreciation::class => 'fixedAssetDepreciationAttributes',
    FixedAssetProposal::class => 'fixedAssetProposalAttributes',
    NumberSeries::class => ['key' => 'invoices', 'next_number' => 1],
    Customer::class => ['number' => 1, 'name' => 'Hemlig kund AB', 'payment_terms_days' => 30],
    Article::class => 'articleAttributes',
    Invoice::class => 'invoiceAttributes',
    InvoiceRow::class => 'invoiceRowAttributes',
    InvoicePayment::class => 'invoicePaymentAttributes',
    SalesDocument::class => 'salesDocumentAttributes',
    SalesDocumentRow::class => 'salesDocumentRowAttributes',
    InvoiceReminder::class => 'reminderAttributes',
    Supplier::class => ['number' => 1, 'name' => 'Hemlig leverantör AB', 'payment_terms_days' => 30],
    SupplierInvoice::class => 'supplierInvoiceAttributes',
    SupplierInvoiceRow::class => 'supplierInvoiceRowAttributes',
    SupplierPayment::class => 'supplierPaymentAttributes',
    SupplierPaymentBatch::class => 'paymentBatchAttributes',
    SupplierPaymentBatchItem::class => 'paymentBatchItemAttributes',
    BankFileImport::class => 'bankFileImportAttributes',
    BankTransaction::class => 'bankTransactionAttributes',
    RecurringInvoice::class => 'agreementAttributes',
    CompanyModule::class => ['module_key' => 'hemlig', 'enabled' => true],
    WorkSchedule::class => 'payrollWorkScheduleAttributes',
    CollectiveAgreement::class => 'payrollCollectiveAgreementAttributes',
    PensionPlan::class => 'payrollPensionPlanAttributes',
    Employee::class => 'payrollEmployeeAttributes',
    Employment::class => 'payrollEmploymentAttributes',
    SalaryChange::class => 'payrollSalaryChangeAttributes',
    PayCode::class => 'payrollPayCodeAttributes',
    PayrollRun::class => 'payrollPayrollRunAttributes',
    PayrollInput::class => 'payrollPayrollInputAttributes',
    RecurringInput::class => 'payrollRecurringInputAttributes',
    Payslip::class => 'payrollPayslipAttributes',
    PayslipLine::class => 'payrollPayslipLineAttributes',
    Accumulator::class => 'payrollAccumulatorAttributes',
    Absence::class => 'payrollAbsenceAttributes',
    Benefit::class => 'payrollBenefitAttributes',
    Expense::class => 'payrollExpenseAttributes',
    TimeReport::class => 'payrollTimeReportAttributes',
    CompTimeEntry::class => 'payrollCompTimeEntryAttributes',
    PayrollRuleVerification::class => 'payrollRuleVerificationAttributes',
    AgreementRuleVerification::class => 'agreementRuleVerificationAttributes',
    AgreementDocument::class => 'agreementDocumentAttributes',
    AgreementProposal::class => 'agreementProposalAttributes',
    VacationLiabilitySnapshot::class => 'payrollVacationLiabilityAttributes',
    VacationYearClosing::class => 'payrollVacationYearClosingAttributes',
    AgiSubmission::class => 'payrollAgiSubmissionAttributes',
    PayrollYearClosing::class => 'payrollYearClosingAttributes',
    PayrollMigration::class => 'payrollMigrationAttributes',
    VacationOpeningBalance::class => 'payrollVacationOpeningAttributes',
    ImportedPayslip::class => 'payrollImportedPayslipAttributes',
    PayrollImportSource::class => 'payrollImportSourceAttributes',
    PayrollImportMapping::class => 'payrollImportMappingAttributes',
    PayrollFileImport::class => 'payrollFileImportAttributes',
    PayrollFileImportLine::class => 'payrollFileImportLineAttributes',
    RecurringInvoiceRow::class => 'agreementRowAttributes',
    StaffLedgerLocation::class => 'staffLedgerLocationAttributes',
    StaffLedgerPerson::class => 'staffLedgerPersonAttributes',
    StaffLedgerDevice::class => 'staffLedgerDeviceAttributes',
    StaffLedgerEntry::class => 'staffLedgerEntryAttributes',
    StaffLedgerTimeProposal::class => 'staffLedgerTimeProposalAttributes',
    PlanningArea::class => ['name' => 'Hemligt kök', 'colour' => '#e4572e'],
    PlanningSkill::class => ['name' => 'Hemlig kompetens'],
    PlanningEmployeeSkill::class => 'planningEmployeeSkillAttributes',
    PlanningShift::class => 'planningShiftAttributes',
    PlanningTemplate::class => ['name' => 'Hemlig mall', 'weeks' => 1],
    PlanningTemplateShift::class => 'planningTemplateShiftAttributes',
    PlanningPublication::class => ['from' => '2026-11-09', 'to' => '2026-11-15', 'published_at' => '2026-11-04 10:00:00+01:00', 'shift_count' => 1],
    PlanningAvailability::class => 'planningAvailabilityAttributes',
    PlanningShiftRequest::class => 'planningShiftRequestAttributes',
    PlanningIcalToken::class => 'planningIcalTokenAttributes',
    PlanningAttestation::class => 'planningAttestationAttributes',
    InboxMailbox::class => 'inboxMailboxAttributes',
    AllowedSender::class => ['pattern' => '@hemlig-leverantor.example'],
    InboxTemplate::class => 'inboxTemplateAttributes',
    InboxMessage::class => 'inboxMessageAttributes',
    InboxAttachment::class => 'inboxAttachmentAttributes',
    BankConnection::class => 'bankConnectionAttributes',
    BankFeedAccount::class => 'bankFeedAccountAttributes',
    BankFeedTransaction::class => 'bankFeedTransactionAttributes',
    BankFeedBalance::class => 'bankFeedBalanceAttributes',
    Task::class => 'taskAttributes',
    TaskComment::class => 'taskCommentAttributes',
    TaskChecklistItem::class => 'taskChecklistItemAttributes',
    TaskRecurrence::class => 'taskRecurrenceAttributes',
    MonthCloseCheck::class => 'monthCloseCheckAttributes',
    RecentItem::class => 'recentItemAttributes',
    ConnectGrant::class => 'connectGrantAttributes',
    ConnectApiKey::class => 'connectApiKeyAttributes',
    IdempotencyRecord::class => 'connectIdempotencyAttributes',
    ConnectWebhook::class => 'connectWebhookAttributes',
    ConnectWebhookDelivery::class => 'connectWebhookDeliveryAttributes',
];

function taskAttributes(): array
{
    return ['title' => 'Hemlig uppgift', 'type' => 'VAT', 'created_by' => User::factory()->create()->id];
}

function taskCommentAttributes(): array
{
    $user = User::factory()->create();

    return ['task_id' => Task::create(taskAttributes())->id, 'user_id' => $user->id, 'body' => 'Hemlig kommentar'];
}

function taskChecklistItemAttributes(): array
{
    return ['task_id' => Task::create(taskAttributes())->id, 'label' => 'Hemlig punkt'];
}

function taskRecurrenceAttributes(): array
{
    return ['title' => 'Hemlig moms', 'type' => 'VAT', 'frequency' => 'MONTHLY', 'due_day' => 12, 'created_by' => User::factory()->create()->id];
}

function monthCloseCheckAttributes(): array
{
    return ['month' => '2026-10', 'step' => 'REPORT', 'checked_by' => User::factory()->create()->id, 'checked_at' => now()];
}

function recentItemAttributes(): array
{
    return ['user_id' => User::factory()->create()->id, 'type' => 'customers', 'record_id' => '1', 'title' => 'Hemlig kund AB', 'url' => '/kunder', 'icon' => 'customer', 'opened_at' => now()];
}

function staffLedgerLocationAttributes(): array
{
    return ['name' => 'Hemlig lokal', 'address_line1' => 'Gatan 1', 'postal_code' => '11122', 'city' => 'Staden', 'business_type' => 'RESTAURANT'];
}

function staffLedgerPersonAttributes(): array
{
    return ['first_name' => 'Hemlig', 'last_name' => 'Person', 'personal_number' => '199001011239', 'personal_number_index' => bin2hex(random_bytes(32))];
}

function staffLedgerDeviceAttributes(): array
{
    return ['location_id' => StaffLedgerLocation::create(staffLedgerLocationAttributes())->id, 'name' => 'Hemlig kiosk', 'created_by' => User::factory()->create()->id];
}

function staffLedgerEntryAttributes(): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'location_id' => StaffLedgerLocation::create(staffLedgerLocationAttributes())->id,
        'type' => 'CHECK_IN',
        'method' => 'KIOSK_PIN',
        'person_name' => 'Hemlig Person',
        'personal_number' => '199001011239',
        'occurred_at' => '2026-11-02 08:00:00',
        'received_at' => '2026-11-02 08:00:01',
        'flags' => [],
        'hash' => str_repeat('a', 64),
    ];
}

function planningEmployeeSkillAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'skill_id' => PlanningSkill::create(['name' => 'Hemlig kompetens '.random_int(1, 999999)])->id];
}

function planningShiftAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'starts_at' => '2026-11-10 08:00', 'ends_at' => '2026-11-10 16:00', 'status' => 'DRAFT'];
}

function planningTemplateShiftAttributes(): array
{
    return ['template_id' => PlanningTemplate::create(['name' => 'Hemlig mall', 'weeks' => 1])->id, 'week_index' => 0, 'weekday' => 1, 'start' => '08:00', 'end' => '16:00'];
}

function planningAvailabilityAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'weekday' => 3, 'kind' => 'UNAVAILABLE', 'note' => 'Hemlig anledning'];
}

function planningShiftRequestAttributes(): array
{
    return ['kind' => 'CLAIM', 'shift_id' => PlanningShift::create(planningShiftAttributes())->id, 'requested_by' => Employee::create(payrollEmployeeAttributes())->id, 'status' => 'PENDING'];
}

function payrollCompTimeEntryAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'date' => '2026-11-10', 'kind' => 'ADJUSTMENT', 'minutes' => 90, 'note' => 'Hemlig anteckning'];
}

function planningAttestationAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'date' => '2026-11-10', 'note' => 'Hemlig anteckning'];
}

function planningIcalTokenAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'token_hash' => hash('sha256', Str::random(48))];
}

function staffLedgerTimeProposalAttributes(): array
{
    return [
        'employee_id' => Employee::create(payrollEmployeeAttributes())->id,
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'days' => ['2026-10-05' => 8.0],
        'proposed_by' => User::factory()->create()->id,
    ];
}

function connectGrantAttributes(): array
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Hemlig app', ['https://app.example/cb']);

    return ['user_id' => User::factory()->create()->id, 'client_id' => $client->getKey(), 'scopes' => ['vouchers:view']];
}

function connectApiKeyAttributes(): array
{
    return ['name' => 'Hemlig nyckel', 'prefix' => 'nnx_hemlig', 'token_hash' => hash('sha256', bin2hex(random_bytes(16))), 'scopes' => ['vouchers:view'], 'expires_at' => now()->addDay(), 'created_by' => User::factory()->create()->id];
}

function connectIdempotencyAttributes(): array
{
    return ['credential' => 'key:'.random_int(1, 999999), 'idempotency_key' => 'hemlig', 'request_hash' => str_repeat('a', 64), 'status' => 'done', 'expires_at' => now()->addDay()];
}

function bankConnectionAttributes(): array
{
    return [
        'provider' => 'fake', 'institution_name' => 'Hemliga banken', 'institution_country' => 'SE', 'psu_type' => 'business',
        'session_id' => 'hemlig-session', 'status' => 'ACTIVE', 'valid_until' => '2026-09-30 00:00:00', 'connected_by' => User::factory()->create()->id, 'connected_at' => now(),
    ];
}

function bankFeedAccountAttributes(): array
{
    return ['bank_connection_id' => BankConnection::create(bankConnectionAttributes())->id, 'provider_account_id' => 'hemligt-konto', 'identification_hash' => 'hemlig-hash', 'currency' => 'SEK'];
}

function bankFeedTransactionAttributes(): array
{
    return ['bank_feed_account_id' => BankFeedAccount::create(bankFeedAccountAttributes())->id, 'fingerprint' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'booking_date' => '2026-03-20', 'amount_ore' => 100, 'currency' => 'SEK'];
}

function bankFeedBalanceAttributes(): array
{
    return ['bank_feed_account_id' => BankFeedAccount::create(bankFeedAccountAttributes())->id, 'balance_date' => '2026-03-20', 'balance_type' => 'CLBD', 'amount_ore' => 100, 'currency' => 'SEK', 'fetched_at' => now()];
}

function connectWebhookAttributes(): array
{
    return ['url' => 'https://hemlig.example.com/hook', 'events' => ['voucher.posted'], 'secret' => 'nnxwh_hemlig', 'secret_prefix' => 'nnxwh_hemlig', 'created_by' => User::factory()->create()->id];
}

function connectWebhookDeliveryAttributes(): array
{
    $webhook = ConnectWebhook::create(connectWebhookAttributes());

    return ['webhook_id' => $webhook->id, 'event_id' => (string) Str::uuid(), 'event_type' => 'voucher.posted', 'body' => '{"hemlig":true}', 'status' => 'pending', 'next_attempt_at' => now()];
}

function inboxMailboxAttributes(): array
{
    return ['slug' => 'hemligt-'.bin2hex(random_bytes(6))];
}

function inboxTemplateAttributes(): array
{
    return ['sender' => 'kassa@hemlig.example', 'kind' => 'DAILY_REPORT', 'format' => 'csv', 'fingerprint' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'name' => 'Hemlig mall', 'mapping' => []];
}

function inboxMessageAttributes(): array
{
    return [
        'channel' => 'MAILGUN', 'recipient' => 'hemligt@in.nonox.app', 'from_email' => 'kassa@hemlig.example', 'received_at' => '2026-10-31 07:00:00',
        'eml_path' => 'inkorg/test/original.eml', 'eml_sha256' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'eml_size' => 100, 'status' => 'NEEDS_REVIEW',
    ];
}

function inboxAttachmentAttributes(): array
{
    return [
        'inbox_message_id' => InboxMessage::create(inboxMessageAttributes())->id,
        'position' => 1, 'filename' => 'hemlig.pdf', 'mime' => 'application/pdf', 'size' => 100, 'sha256' => str_repeat('0', 64), 'path' => 'inkorg/test/1.pdf',
    ];
}

/** Runs inside the company's context. */
function voucherAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'series_id' => VoucherSeries::query()->firstOrCreate(['code' => 'A'], ['name' => 'Manuell'])->id,
        'voucher_date' => '2026-03-01',
        'description' => 'Hemlig verifikation',
    ];
}

function voucherRowAttributes(): array
{
    return [
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'account_id' => Account::query()->firstOrCreate(['number' => 1930], ['name' => 'Bank', 'type' => 'ASSET'])->id,
        'debit_ore' => 100,
        'credit_ore' => 0,
    ];
}

function openingBalanceAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'account_id' => Account::query()->firstOrCreate(['number' => 1930], ['name' => 'Bank', 'type' => 'ASSET'])->id,
        'amount_ore' => 100,
    ];
}

function sieImportAttributes(): array
{
    return [
        'user_id' => User::factory()->create()->id,
        'original_name' => 'SIE4 2026.se',
        'file_path' => 'sie-import/test.se',
        'size' => 100,
        'sha256' => str_repeat('0', 64),
        'report' => [],
    ];
}

function importedVoucherAttributes(): array
{
    $voucher = Voucher::create(voucherAttributes());

    return [
        'sie_import_id' => SieImport::create(sieImportAttributes())->id,
        'voucher_id' => $voucher->id,
        'fiscal_year_id' => $voucher->fiscal_year_id,
        'original_series' => 'A',
        'original_number' => (string) random_int(1, 1_000_000),
    ];
}

function voucherRowHistoryAttributes(): array
{
    return [
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'change' => 'REMOVED',
        'position' => 0,
        'account_number' => 1930,
        'amount_ore' => 100,
    ];
}

function fixedAssetAttributes(): array
{
    return [
        'number' => random_int(1, 1_000_000),
        'name' => 'Hemlig espressomaskin',
        'category' => 'EQUIPMENT',
        'acquired_on' => '2026-01-15',
        'acquisition_ore' => 4_000_000,
        'useful_life_months' => 60,
        'depreciation_start' => '2026-01-01',
        'asset_account' => 1220,
        'accumulated_account' => 1229,
        'expense_account' => 7832,
        'created_by' => User::factory()->create()->id,
    ];
}

function fixedAssetDepreciationAttributes(): array
{
    return [
        'fixed_asset_id' => FixedAsset::create(fixedAssetAttributes())->id,
        'period' => '2026-0'.random_int(1, 9),
        'amount_ore' => 66_667,
        'voucher_id' => Voucher::create(voucherAttributes())->id,
    ];
}

function fixedAssetProposalAttributes(): array
{
    return [
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'line' => 0,
        'data' => ['name' => 'Hemlig espressomaskin', 'category' => 'EQUIPMENT', 'acquisition' => '', 'life_months' => 60, 'acquired_on' => null, 'depreciation_start' => null],
        'created_by' => User::factory()->create()->id,
    ];
}

function bankReconciliationAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'account_id' => Account::query()->firstOrCreate(['number' => 1930], ['name' => 'Bank', 'type' => 'ASSET'])->id,
        'statement_date' => '2026-03-31',
        'statement_balance_ore' => 100_000,
        'ledger_balance_ore' => 100_000,
        'outstanding_ore' => 0,
        'difference_ore' => 0,
        'outstanding_row_ids' => [],
        'reconciled_by' => User::factory()->create()->id,
    ];
}

function fortnoxConnectionAttributes(): array
{
    return [
        'refresh_token' => 'secret',
        'progress' => [],
        'connected_by' => User::factory()->create()->id,
    ];
}

function vatNumberCheckAttributes(): array
{
    return [
        'vat_number' => 'DE123456789',
        'valid' => true,
        'checked_at' => '2026-03-02 10:00:00',
        'checked_by' => User::factory()->create()->id,
    ];
}

function closingCheckAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'key' => 'cash_count_'.random_int(1, 1_000_000),
        'checked_by' => User::factory()->create()->id,
    ];
}

function yearEndEntryAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'type' => 'TAX',
        'amount_ore' => 1_648_000,
        'created_by' => User::factory()->create()->id,
    ];
}

function incomeTaxReturnAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'adjustments' => ['7653' => 1000],
        'updated_by' => User::factory()->create()->id,
    ];
}

function annualReportAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'business_description' => 'Hemlig verksamhet',
        'signatories' => [],
        'updated_by' => User::factory()->create()->id,
    ];
}

function accrualAttributes(): array
{
    return [
        'number' => random_int(1, 1_000_000),
        'name' => 'Hemlig försäkring',
        'kind' => 'PREPAID_EXPENSE',
        'total_ore' => 1_200_000,
        'balance_account' => 1790,
        'result_account' => 6310,
        'start_month' => '2026-01',
        'months' => 12,
        'created_by' => User::factory()->create()->id,
    ];
}

function accrualPostingAttributes(): array
{
    return [
        'accrual_id' => Accrual::create(accrualAttributes())->id,
        'period' => '2026-0'.random_int(1, 9),
        'type' => 'RELEASE',
        'amount_ore' => 100_000,
        'voucher_id' => Voucher::create(voucherAttributes())->id,
    ];
}

function clientProfileAttributes(): array
{
    return ['note' => 'Hemlig anteckning'];
}

function archivePackageAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'created_by' => User::factory()->create()->id,
    ];
}

function vatReportAttributes(): array
{
    return [
        'period_start' => '2026-0'.random_int(1, 9).'-01',
        'period_end' => '2026-01-31',
        'boxes' => [49 => 0],
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'created_by' => User::factory()->create()->id,
    ];
}

function articleAttributes(): array
{
    return [
        'number' => 'A'.random_int(1, 1_000_000),
        'name' => 'Hemlig artikel',
        'price_ore' => 100,
        'vat_rate' => 2500,
        'account_id' => Account::query()->firstOrCreate(['number' => 3001], ['name' => 'Försäljning', 'type' => 'REVENUE'])->id,
    ];
}

function invoiceAttributes(): array
{
    return [
        'customer_id' => Customer::query()->firstOrCreate(['number' => 1], ['name' => 'Hemlig kund AB'])->id,
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-31',
    ];
}

function invoiceRowAttributes(): array
{
    return [
        'invoice_id' => Invoice::create(invoiceAttributes())->id,
        'description' => 'Hemlig rad',
        'quantity' => '1',
        'unit_price_ore' => 100,
        'vat_rate' => 2500,
        'account_id' => articleAttributes()['account_id'],
        'net_ore' => 100,
        'position' => 0,
    ];
}

function invoicePaymentAttributes(): array
{
    return [
        'invoice_id' => Invoice::create(invoiceAttributes())->id,
        'paid_on' => '2026-03-10',
        'amount_ore' => 100,
        'account_id' => Account::query()->firstOrCreate(['number' => 1930], ['name' => 'Bank', 'type' => 'ASSET'])->id,
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'created_by' => User::factory()->create()->id,
    ];
}

function supplierInvoiceAttributes(): array
{
    return [
        'supplier_id' => Supplier::query()->firstOrCreate(['number' => 1], ['name' => 'Hemlig leverantör AB'])->id,
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-31',
    ];
}

function supplierInvoiceRowAttributes(): array
{
    return [
        'supplier_invoice_id' => SupplierInvoice::create(supplierInvoiceAttributes())->id,
        'account_id' => Account::query()->firstOrCreate(['number' => 4010], ['name' => 'Inköp', 'type' => 'EXPENSE'])->id,
        'amount_ore' => 100,
        'position' => 1,
    ];
}

function supplierPaymentAttributes(): array
{
    return [
        'supplier_invoice_id' => SupplierInvoice::create(supplierInvoiceAttributes())->id,
        'paid_on' => '2026-03-10',
        'amount_ore' => 100,
        'account_id' => Account::query()->firstOrCreate(['number' => 1930], ['name' => 'Bank', 'type' => 'ASSET'])->id,
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'created_by' => User::factory()->create()->id,
    ];
}

function paymentBatchAttributes(): array
{
    return [
        'number' => random_int(1, 1_000_000),
        'bank' => 'NORDEA',
        'execution_date' => '2026-03-12',
        'debtor_iban' => 'SE4550000000058398257466',
        'debtor_bic' => 'NDEASESS',
        'message_id' => 'NONOX-'.random_int(1, 1_000_000),
        'payment_count' => 1,
        'total_ore' => 100,
        'file_path' => 'betalfiler/hemlig.xml',
        'file_sha256' => str_repeat('0', 64),
        'created_by' => User::factory()->create()->id,
    ];
}

function paymentBatchItemAttributes(): array
{
    return [
        'batch_id' => SupplierPaymentBatch::create(paymentBatchAttributes())->id,
        'supplier_invoice_id' => SupplierInvoice::create(supplierInvoiceAttributes())->id,
        'amount_ore' => 100,
        'end_to_end_id' => 'LF1',
    ];
}

function bankFileImportAttributes(): array
{
    return [
        'file_name' => 'hemlig.xml',
        'file_path' => 'bankfiler/hemlig.xml',
        'file_sha256' => str_repeat('0', 64),
        'credit_count' => 1,
        'credit_total_ore' => 100,
        'created_by' => User::factory()->create()->id,
    ];
}

function bankTransactionAttributes(): array
{
    return [
        'bank_file_import_id' => BankFileImport::create(bankFileImportAttributes())->id,
        'reference' => 'REF-'.random_int(1, 1_000_000),
        'booking_date' => '2026-03-20',
        'amount_ore' => 100,
    ];
}

function agreementAttributes(): array
{
    return [
        'number' => random_int(1, 1_000_000),
        'customer_id' => Customer::query()->firstOrCreate(['number' => 1], ['name' => 'Hemlig kund AB'])->id,
        'name' => 'Hemligt avtal',
        'interval_months' => 1,
        'invoice_day' => 1,
        'next_invoice_date' => '2026-04-01',
        'created_by' => User::factory()->create()->id,
    ];
}

function agreementRowAttributes(): array
{
    return [
        'recurring_invoice_id' => RecurringInvoice::create(agreementAttributes())->id,
        'description' => 'Hemlig rad',
        'quantity' => '1',
        'unit_price_ore' => 100,
        'vat_rate' => 2500,
        'account_id' => articleAttributes()['account_id'],
        'net_ore' => 100,
        'position' => 0,
    ];
}

function payrollWorkScheduleAttributes(): array
{
    return ['name' => 'Heltid', 'pattern' => ['weeks' => [[8, 8, 8, 8, 8, 0, 0]]]];
}

function payrollCollectiveAgreementAttributes(): array
{
    return ['name' => 'Generisk', 'rules' => []];
}

function payrollPensionPlanAttributes(): array
{
    return ['name' => 'Pension', 'template' => 'CUSTOM', 'rules' => []];
}

function payrollEmployeeAttributes(): array
{
    return [
        'employee_number' => random_int(1, 1_000_000),
        'first_name' => 'Hemlig',
        'last_name' => 'Anställd',
        'personal_number' => '198001010000',
        'birth_date' => '1980-01-01',
    ];
}

function payrollEmploymentAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'start_date' => '2026-01-01', 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY', 'monthly_salary_ore' => 3_000_000];
}

function payrollSalaryChangeAttributes(): array
{
    $employment = Employment::create(payrollEmploymentAttributes());

    return ['employee_id' => $employment->employee_id, 'employment_id' => $employment->id, 'valid_from' => '2026-04-01', 'monthly_salary_ore' => 3_100_000];
}

function payrollPayCodeAttributes(): array
{
    return ['code' => 'L'.random_int(1, 1_000_000), 'name_sv' => 'Månadslön', 'name_en' => 'Monthly salary', 'category' => 'SALARY', 'unit' => 'AMOUNT', 'is_taxable' => true, 'tax_method' => 'TABLE', 'is_contribution_basis' => true, 'is_vacation_basis' => true, 'is_pension_basis' => true];
}

function payrollPayrollRunAttributes(): array
{
    return ['period' => '2026-04', 'payment_date' => '2026-04-24'];
}

function payrollPayrollInputAttributes(): array
{
    return ['payroll_run_id' => PayrollRun::create(payrollPayrollRunAttributes())->id, 'employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'pay_code_id' => PayCode::create(payrollPayCodeAttributes())->id, 'amount_ore' => 100];
}

function payrollRecurringInputAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'pay_code_id' => PayCode::create(payrollPayCodeAttributes())->id, 'amount_ore' => 100, 'valid_from' => '2026-01-01'];
}

function payrollPayslipAttributes(): array
{
    $employment = Employment::create(payrollEmploymentAttributes());

    return ['payroll_run_id' => PayrollRun::create(payrollPayrollRunAttributes())->id, 'employee_id' => $employment->employee_id, 'employment_id' => $employment->id];
}

function payrollPayslipLineAttributes(): array
{
    return ['payslip_id' => Payslip::create(payrollPayslipAttributes())->id, 'pay_code_id' => PayCode::create(payrollPayCodeAttributes())->id, 'description' => 'Månadslön', 'amount_ore' => 100, 'tax_effect' => 'TABLE', 'explanation' => []];
}

function payrollAccumulatorAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'scope' => 'CALENDAR_YEAR', 'year' => 2026, 'key' => 'gross_ytd', 'value' => 100];
}

function payrollAbsenceAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'type' => 'SICK', 'date_from' => '2026-03-02', 'date_to' => '2026-03-03'];
}

function payrollBenefitAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'type' => 'MEAL', 'value_rule' => [], 'valid_from' => '2026-01-01'];
}

function payrollExpenseAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'type' => 'OUTLAY', 'date' => '2026-03-02', 'amount_ore' => 100];
}

function payrollRuleVerificationAttributes(): array
{
    return [
        'payroll_rule_version_id' => PayrollRuleVersion::create(['key' => 'test.'.random_int(1, 1_000_000), 'valid_from' => '2026-01-01', 'value' => ['bp' => 1], 'source_url' => 'https://example.test'])->id,
        'verified_by' => User::factory()->create()->id,
        'verifier_name' => 'Testperson',
        'verified_at' => now(),
    ];
}

function agreementRuleVerificationAttributes(): array
{
    return [
        'agreement_rule_version_id' => AgreementRuleVersion::create(['agreement_key' => 'test-'.random_int(1, 1_000_000), 'variant' => 'MEMBER', 'valid_from' => '2026-01-01', 'rules' => [], 'sources' => [], 'checksum' => str_repeat('0', 64)])->id,
        'verified_by' => User::factory()->create()->id,
        'verifier_name' => 'Testperson',
        'verified_at' => now(),
    ];
}

function agreementDocumentAttributes(): array
{
    return ['document_type' => 'HANGAVTAL', 'original_name' => 'hemligt.txt', 'file_path' => 'agreement-documents/hemligt.txt', 'mime' => 'text/plain', 'size' => 10, 'sha256' => bin2hex(random_bytes(32)), 'uploaded_by' => User::factory()->create()->id];
}

function agreementProposalAttributes(): array
{
    return ['agreement_document_id' => AgreementDocument::create(agreementDocumentAttributes())->id, 'agent' => 'IDENTIFICATION', 'field' => 'binding', 'value' => ['agreement_key' => 'hemligt'], 'quote' => 'Hemligt citat', 'status' => 'PENDING'];
}

function payrollVacationLiabilityAttributes(): array
{
    $year = random_int(2030, 2099);

    return [
        'payroll_run_id' => PayrollRun::create(['period' => "{$year}-04", 'payment_date' => "{$year}-04-24"])->id,
        'as_of' => "{$year}-04-30",
        'liability_ore' => 100,
        'contributions_ore' => 31,
        'employees' => [],
    ];
}

function payrollVacationYearClosingAttributes(): array
{
    return [
        'employee_id' => Employee::create(payrollEmployeeAttributes())->id,
        'year_start' => '2025-04-01', 'year_end' => '2026-03-31',
        'days_earned' => 25, 'days_taken' => 20, 'saved_days_used' => 0, 'days_saved' => 5, 'days_paid_out' => 0,
        'payout_ore' => 0, 'variable_basis_ore' => 0, 'variable_pay_ore' => 0,
        'closed_by' => User::factory()->create()->id,
    ];
}

function payrollMigrationAttributes(): array
{
    return ['year' => 2026, 'report' => [], 'started_by' => User::factory()->create()->id];
}

function payrollVacationOpeningAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'year_start' => '2026-04-01', 'days_earned' => 25, 'days_taken' => 0, 'days_saved' => 0];
}

function payrollImportedPayslipAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'period' => '2026-01', 'file_path' => 'imported/x.pdf', 'original_name' => 'x.pdf', 'sha256' => bin2hex(random_bytes(32)), 'uploaded_by' => User::factory()->create()->id];
}

function payrollImportSourceAttributes(): array
{
    return ['name' => 'Hemligt schema '.random_int(1, 1_000_000)];
}

function payrollImportMappingAttributes(): array
{
    return ['payroll_import_source_id' => PayrollImportSource::create(payrollImportSourceAttributes())->id, 'external_code' => 'OB1'];
}

function payrollFileImportAttributes(): array
{
    return [
        'payroll_run_id' => PayrollRun::create(payrollPayrollRunAttributes())->id,
        'payroll_import_source_id' => PayrollImportSource::create(payrollImportSourceAttributes())->id,
        'period' => '2026-04', 'format' => 'PAXML', 'original_name' => 'x.xml', 'file_path' => 'payroll-imports/x.xml',
        'sha256' => bin2hex(random_bytes(32)), 'size' => 1,
    ];
}

function payrollFileImportLineAttributes(): array
{
    return ['payroll_file_import_id' => PayrollFileImport::create(payrollFileImportAttributes())->id, 'line_no' => 1, 'section' => 'lonetrans', 'external_code' => 'OB1', 'status' => 'PROPOSED'];
}

function payrollYearClosingAttributes(): array
{
    return ['year' => random_int(2000, 2099), 'totals' => [], 'closed_by' => User::factory()->create()->id];
}

function payrollAgiSubmissionAttributes(): array
{
    return [
        'period' => '2026-03', 'sequence' => random_int(1, 1_000_000), 'xml_path' => 'agi/test.xml', 'sha256' => str_repeat('a', 64),
        'tax_ore' => 0, 'contributions_ore' => 0, 'individuals' => [], 'generated_by' => User::factory()->create()->id,
    ];
}

function payrollTimeReportAttributes(): array
{
    return ['employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'date' => '2026-03-02', 'hours' => '8', 'pay_code_id' => PayCode::create(payrollPayCodeAttributes())->id];
}

function salesDocumentAttributes(): array
{
    return [
        'kind' => 'QUOTE',
        'number' => random_int(1, 1_000_000),
        'customer_id' => Customer::query()->firstOrCreate(['number' => 1], ['name' => 'Hemlig kund AB'])->id,
        'document_date' => '2026-04-01',
    ];
}

function salesDocumentRowAttributes(): array
{
    return [...array_diff_key(invoiceRowAttributes(), ['invoice_id' => true]), 'sales_document_id' => SalesDocument::create(salesDocumentAttributes())->id];
}

function reminderAttributes(): array
{
    return [
        'invoice_id' => Invoice::create(invoiceAttributes())->id,
        'sequence' => 1,
        'sent_on' => '2026-04-01',
        'pay_by' => '2026-04-11',
        'remaining_ore' => 100,
        'sent_to' => 'kund@exempel.test',
        'created_by' => User::factory()->create()->id,
    ];
}

function attachmentAttributes(): array
{
    return [
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'file_path' => 'underlag/test.pdf',
        'original_name' => 'kvitto.pdf',
        'mime' => 'application/pdf',
        'size' => 100,
        'sha256' => str_repeat('0', 64),
    ];
}

function counterAttributes(): array
{
    $voucher = voucherAttributes();

    return ['fiscal_year_id' => $voucher['fiscal_year_id'], 'series_id' => $voucher['series_id'], 'next_number' => 1];
}

/** @return array<string, mixed> */
function attributesFor(string $model): array
{
    $attributes = COMPANY_SCOPED_MODELS[$model];

    return is_string($attributes) ? $attributes() : $attributes;
}

/** Models whose rows may never be updated; the bulk test only deletes them. */
const NEVER_UPDATED = [VoucherAttachment::class];

/** Models that can only be added to: the database refuses bulk changes too. */
const APPEND_ONLY = [VatNumberCheck::class, AccrualPosting::class, YearEndEntry::class, BankReconciliation::class, FixedAssetDepreciation::class, ImportedVoucher::class, VoucherRowHistory::class, VatReport::class, InvoicePayment::class, InvoiceReminder::class, SupplierPayment::class, SupplierPaymentBatch::class, SupplierPaymentBatchItem::class, BankFileImport::class, BankTransaction::class, BankFeedTransaction::class, PayrollRuleVerification::class, VacationLiabilitySnapshot::class, VacationYearClosing::class, PayrollYearClosing::class, StaffLedgerEntry::class, AgreementRuleVerification::class];

/** Test-only models that are not part of the application. */
const TENANCY_FIXTURES = [TenantNote::class];

beforeEach(function () {
    TenantNote::createTable();

    $this->companyA = Company::factory()->create(['name' => 'Företag A']);
    $this->companyB = Company::factory()->create(['name' => 'Företag B']);
});

/**
 * @param  class-string<Model>  $model
 */
function createFor(Company $company, string $model): Model
{
    return CurrentCompany::run($company, fn () => $model::create(attributesFor($model)));
}

dataset('company-scoped models', fn () => array_keys(COMPANY_SCOPED_MODELS));

it('fills in the current company when a record is created', function (string $model) {
    $record = createFor($this->companyA, $model);

    expect($record->company_id)->toBe($this->companyA->id);
})->with('company-scoped models');

it('never shows another company\'s records', function (string $model) {
    $own = createFor($this->companyA, $model);
    $foreign = createFor($this->companyB, $model);

    CurrentCompany::set($this->companyA);

    expect($model::all()->modelKeys())->toBe([$own->getKey()])
        ->and($model::count())->toBe(1)
        ->and($model::find($foreign->getKey()))->toBeNull()
        ->and($model::whereKey($foreign->getKey())->exists())->toBeFalse()
        ->and(fn () => $model::findOrFail($foreign->getKey()))->toThrow(ModelNotFoundException::class);
})->with('company-scoped models');

it('shows nothing and refuses to create when no company is known', function (string $model) {
    createFor($this->companyA, $model);

    CurrentCompany::set(null);

    expect($model::count())->toBe(0)
        ->and(fn () => $model::create(CurrentCompany::run($this->companyA, fn () => attributesFor($model))))->toThrow(MissingCompanyContext::class)
        ->and(fn () => $model::create([...CurrentCompany::run($this->companyB, fn () => attributesFor($model)), 'company_id' => $this->companyB->id]))->toThrow(MissingCompanyContext::class);
})->with('company-scoped models');

it('refuses to create a record for another company', function (string $model) {
    CurrentCompany::set($this->companyA);

    $attributes = CurrentCompany::run($this->companyA, fn () => attributesFor($model));

    expect(fn () => $model::create([...$attributes, 'company_id' => $this->companyB->id]))
        ->toThrow(MissingCompanyContext::class);
})->with('company-scoped models');

it('refuses to move a record to another company', function (string $model) {
    $record = createFor($this->companyA, $model);

    CurrentCompany::set($this->companyA);
    $record->company_id = $this->companyB->id;

    expect(fn () => $record->save())->toThrow(MissingCompanyContext::class);
    expect(CompanyScope::bypass(fn () => $model::find($record->getKey())->company_id))->toBe($this->companyA->id);
})->with('company-scoped models');

it('limits bulk updates and deletes to the current company', function (string $model) {
    $own = createFor($this->companyA, $model);
    $foreign = createFor($this->companyB, $model);

    CurrentCompany::set($this->companyA);

    if (in_array($model, APPEND_ONLY, true)) {
        expect(fn () => DB::transaction(fn () => $model::query()->delete()))->toThrow(QueryException::class)
            ->and(CompanyScope::bypass(fn () => $model::all()->modelKeys()))->toEqualCanonicalizing([$own->getKey(), $foreign->getKey()]);

        return;
    }

    if (! in_array($model, NEVER_UPDATED, true)) {
        $model::query()->update(['updated_at' => now()->addDay()]);
    }

    $model::query()->delete();

    $remaining = CompanyScope::bypass(fn () => $model::all()->modelKeys());

    expect($remaining)->toBe([$foreign->getKey()])
        ->and($remaining)->not->toContain($own->getKey());
})->with('company-scoped models');

it('only lets an explicit bypass see every company', function (string $model) {
    createFor($this->companyA, $model);
    createFor($this->companyB, $model);

    CurrentCompany::set($this->companyA);

    expect($model::count())->toBe(1)
        ->and(CompanyScope::bypass(fn () => $model::count()))->toBe(2)
        ->and($model::count())->toBe(1);
})->with('company-scoped models');

it('runs queued jobs only inside the company they were dispatched for', function (string $model) {
    createFor($this->companyA, $model);
    createFor($this->companyB, $model);

    CurrentCompany::set($this->companyB);
    ListNotesForCompany::$seenCompanyIds = [];

    ListNotesForCompany::dispatch($this->companyA->id, $model);

    expect(ListNotesForCompany::$seenCompanyIds)->toBe([$this->companyA->id])
        ->and(CurrentCompany::id())->toBe($this->companyB->id);
})->with('company-scoped models');

it('answers 404, not 403, when a user opens another company\'s record by id', function (string $model) {
    $foreign = createFor($this->companyB, $model);
    $own = createFor($this->companyA, $model);

    Route::middleware(['web', 'auth'])->get('/tenancy-test/{record}', fn (string $record) => $model::findOrFail($record)->getKey());

    $user = User::factory()->create();
    $this->companyA->users()->attach($user, ['role' => 'admin']);
    $user->switchCompany($this->companyA->fresh());

    $this->actingAs($user->fresh())->get("/tenancy-test/{$foreign->getKey()}")->assertNotFound();
    $this->actingAs($user->fresh())->get("/tenancy-test/{$own->getKey()}")->assertOk();
})->with('company-scoped models');

it('lists every model that uses BelongsToCompany', function () {
    $usingTrait = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! preg_match('/^\s*use BelongsToCompany;/m', $source)) {
            continue;
        }

        preg_match('/^namespace (.+);/m', $source, $namespace);
        preg_match('/^(?:final |abstract )?class (\w+)/m', $source, $class);

        $usingTrait[] = $namespace[1].'\\'.$class[1];
    }

    $registered = array_values(array_diff(array_keys(COMPANY_SCOPED_MODELS), TENANCY_FIXTURES));

    sort($usingTrait);
    sort($registered);

    expect($usingTrait)->toBe($registered, 'Every model using BelongsToCompany must be listed in COMPANY_SCOPED_MODELS.');
});
