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
use App\Models\CompanyAccountingPolicy;
use App\Models\CompanyGuideStep;
use App\Models\CompanyHolding;
use App\Models\CompanyModule;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\DeadlineCalendarFeed;
use App\Models\DeadlineReminder;
use App\Models\DeferredTaxItem;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetProposal;
use App\Models\ImportedVoucher;
use App\Models\IncomeTaxPartner;
use App\Models\IncomeTaxReturn;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Models\InvoicePayment;
use App\Models\InvoiceReminder;
use App\Models\InvoiceRow;
use App\Models\InvoiceTaxReductionBuyer;
use App\Models\K10Owner;
use App\Models\K10Year;
use App\Models\MileageRoute;
use App\Models\MonthCloseCheck;
use App\Models\NumberSeries;
use App\Models\OpeningBalance;
use App\Models\PaymentResponse;
use App\Models\PaymentResponseImport;
use App\Models\PriorYearSizeFigure;
use App\Models\Project;
use App\Models\RecentItem;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRow;
use App\Models\RegisterImport;
use App\Models\RegisterImportPreset;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Models\SieImport;
use App\Models\SigningEvent as CrmSigningEvent;
use App\Models\SigningRequest as CrmSigningRequest;
use App\Models\SkatteverketEvent;
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
use App\Models\TaxAccountPosting;
use App\Models\User;
use App\Models\VatNumberCheck;
use App\Models\VatReport;
use App\Models\VatSubmission;
use App\Models\Vehicle;
use App\Models\VehicleTrip;
use App\Models\VehicleYearReading;
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
use App\Modules\Boardroom\Models\AgendaItem as BoardroomAgendaItem;
use App\Modules\Boardroom\Models\ArticlesVersion as BoardroomArticlesVersion;
use App\Modules\Boardroom\Models\Meeting as BoardroomMeeting;
use App\Modules\Boardroom\Models\Minutes as BoardroomMinutes;
use App\Modules\Boardroom\Models\Register as BoardroomRegister;
use App\Modules\Boardroom\Models\ShareClass as BoardroomShareClass;
use App\Modules\Boardroom\Models\ShareEvent as BoardroomShareEvent;
use App\Modules\Boardroom\Models\Shareholder as BoardroomShareholder;
use App\Modules\Connect\Models\ConnectApiKey;
use App\Modules\Connect\Models\ConnectGrant;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Models\ConnectSftpKey;
use App\Modules\Connect\Models\ConnectSftpUpload;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Connect\Models\IdempotencyRecord;
use App\Modules\Crm\Models\Account as CrmAccount;
use App\Modules\Crm\Models\AccountPerson as CrmAccountPerson;
use App\Modules\Crm\Models\Activity as CrmActivity;
use App\Modules\Crm\Models\Agreement as CrmAgreement;
use App\Modules\Crm\Models\CalendarConnection as CrmCalendarConnection;
use App\Modules\Crm\Models\CalendarEvent as CrmCalendarEvent;
use App\Modules\Crm\Models\CalendarFeed as CrmCalendarFeed;
use App\Modules\Crm\Models\CallList as CrmCallList;
use App\Modules\Crm\Models\CallListItem as CrmCallListItem;
use App\Modules\Crm\Models\Deal as CrmDeal;
use App\Modules\Crm\Models\DealStageChange as CrmDealStageChange;
use App\Modules\Crm\Models\Email as CrmEmail;
use App\Modules\Crm\Models\EmailTemplate as CrmEmailTemplate;
use App\Modules\Crm\Models\Form as CrmForm;
use App\Modules\Crm\Models\FormSubmission as CrmFormSubmission;
use App\Modules\Crm\Models\Goal as CrmGoal;
use App\Modules\Crm\Models\LossReason as CrmLossReason;
use App\Modules\Crm\Models\Mailing as CrmMailing;
use App\Modules\Crm\Models\MailingLink as CrmMailingLink;
use App\Modules\Crm\Models\MailingRecipient as CrmMailingRecipient;
use App\Modules\Crm\Models\Person as CrmPerson;
use App\Modules\Crm\Models\Pipeline as CrmPipeline;
use App\Modules\Crm\Models\PipelineStage as CrmPipelineStage;
use App\Modules\Crm\Models\QuoteTemplate as CrmQuoteTemplate;
use App\Modules\Crm\Models\QuoteTemplateRow as CrmQuoteTemplateRow;
use App\Modules\Crm\Models\RegistryNotice as CrmRegistryNotice;
use App\Modules\Crm\Models\Segment as CrmSegment;
use App\Modules\Crm\Models\Suppression as CrmSuppression;
use App\Modules\Inbox\Models\AllowedSender;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMailbox;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\InboxTemplate;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Models\LabelTemplate;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\ScanOperation;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use App\Modules\Inventory\Models\StockValuation;
use App\Modules\Kitchen\Models\ConsumptionCredit;
use App\Modules\Kitchen\Models\ConsumptionRun;
use App\Modules\Kitchen\Models\ConsumptionWait;
use App\Modules\Kitchen\Models\MealPayrollProposal;
use App\Modules\Kitchen\Models\SaleConsumption;
use App\Modules\Kitchen\Models\StaffMeal;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\Kitchen\Models\WasteMeasurement;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Accumulator;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\AgiUpload;
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
use App\Modules\Payroll\Models\SickPayPeriod;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\Models\VacationLiabilitySnapshot;
use App\Modules\Payroll\Models\VacationOpeningBalance;
use App\Modules\Payroll\Models\VacationYearClosing;
use App\Modules\Payroll\Models\WorkSchedule;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
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
use App\Modules\Portal\Models\PortalAttachment;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalMessage;
use App\Modules\Portal\Models\PortalPaymentNote;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosFee;
use App\Modules\Pos\Models\PosLogEntry;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleRow;
use App\Modules\Pos\Models\PosStockPush;
use App\Modules\Pos\Models\PosWebhookMessage;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Models\SaasCorrection;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasEstimate;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\Models\SaasSeparatePair;
use App\Modules\StaffLedger\Models\Device as StaffLedgerDevice;
use App\Modules\StaffLedger\Models\Entry as StaffLedgerEntry;
use App\Modules\StaffLedger\Models\Location as StaffLedgerLocation;
use App\Modules\StaffLedger\Models\Person as StaffLedgerPerson;
use App\Modules\StaffLedger\Models\TimeProposal as StaffLedgerTimeProposal;
use App\Modules\WorkOrders\Models\ChangeOrder as WorkOrderChangeOrder;
use App\Modules\WorkOrders\Models\ContactAttempt as WorkOrderContactAttempt;
use App\Modules\WorkOrders\Models\LabourKind as WorkOrderLabourKind;
use App\Modules\WorkOrders\Models\MaterialEntry as WorkOrderMaterialEntry;
use App\Modules\WorkOrders\Models\OnSiteCode as WorkOrderOnSiteCode;
use App\Modules\WorkOrders\Models\PaymentInstalment as WorkOrderPaymentInstalment;
use App\Modules\WorkOrders\Models\TimeEntry as WorkOrderTimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\Models\WorkOrderBuyer;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use App\Modules\WorkOrders\Models\WorkOrderPhoto;
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
    Vehicle::class => ['kind' => 'COMPANY_CAR', 'label' => 'Hemlig bil', 'registration_number' => 'HEM123', 'fuel' => 'PETROL'],
    VehicleYearReading::class => 'vehicleYearReadingAttributes',
    VehicleTrip::class => 'vehicleTripAttributes',
    MileageRoute::class => 'mileageRouteAttributes',
    // Records that need other records of the same company get a closure.
    Voucher::class => 'voucherAttributes',
    VoucherRow::class => 'voucherRowAttributes',
    VoucherNumberCounter::class => 'counterAttributes',
    VoucherAttachment::class => 'attachmentAttributes',
    OpeningBalance::class => 'openingBalanceAttributes',
    SieImport::class => 'sieImportAttributes',
    RegisterImport::class => 'registerImportAttributes',
    RegisterImportPreset::class => ['kind' => 'customers', 'name' => 'Hemligt format', 'mapping' => ['name' => 'Namn']],
    ImportedVoucher::class => 'importedVoucherAttributes',
    VoucherRowHistory::class => 'voucherRowHistoryAttributes',
    VatReport::class => 'vatReportAttributes',
    VatSubmission::class => ['period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'status' => 'ready_to_sign', 'boxes' => [49 => 1200]],
    TaxAccountPosting::class => ['transaction_id' => 746876987, 'transaction_date' => '2026-02-12', 'amount_ore' => -125_000, 'account_number' => 2650],
    SkatteverketEvent::class => ['event_id' => 'skv-tenancy-1', 'type' => 'SKV.TEST.TY_TEST_V1', 'producer' => 'skatteverket', 'title' => 'Händelse', 'description' => 'Beskrivning', 'language' => 'sv', 'requires_action' => true, 'case_closed' => false, 'fetched_at' => '2026-02-15 10:00:00'],
    ArchivePackage::class => 'archivePackageAttributes',
    ClientProfile::class => 'clientProfileAttributes',
    FixedAsset::class => 'fixedAssetAttributes',
    Accrual::class => 'accrualAttributes',
    AnnualReport::class => 'annualReportAttributes',
    IncomeTaxReturn::class => 'incomeTaxReturnAttributes',
    IncomeTaxPartner::class => 'incomeTaxPartnerAttributes',
    K10Year::class => 'k10YearAttributes',
    K10Owner::class => 'k10OwnerAttributes',
    CompanyAccountingPolicy::class => ['key' => 'leasing', 'value' => 'classify', 'applies_from' => '2026-01-01'],
    CompanyHolding::class => ['held_org_number' => '556036-0793', 'held_name' => 'Hemligt Dotterbolag AB', 'capital_bp' => 10000, 'voting_bp' => 10000, 'control_basis' => 'VOTES', 'acquired_on' => '2024-01-01'],
    PriorYearSizeFigure::class => ['start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'average_employees_fte' => '3.40', 'balance_sheet_total_ore' => 150_000_000, 'net_turnover_ore' => 300_000_000],
    ClosingCheck::class => 'closingCheckAttributes',
    YearEndEntry::class => 'yearEndEntryAttributes',
    DeferredTaxItem::class => 'deferredTaxItemAttributes',
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
    PaymentResponseImport::class => 'paymentResponseImportAttributes',
    PaymentResponse::class => 'paymentResponseAttributes',
    RecurringInvoice::class => 'agreementAttributes',
    CompanyModule::class => ['module_key' => 'hemlig', 'enabled' => true],
    CompanyGuideStep::class => 'companyGuideStepAttributes',
    DeadlineCalendarFeed::class => 'deadlineCalendarFeedAttributes',
    DeadlineReminder::class => 'deadlineReminderAttributes',
    WorkSchedule::class => 'payrollWorkScheduleAttributes',
    PeppolParticipant::class => 'peppolParticipantAttributes',
    PeppolTransmission::class => 'peppolTransmissionAttributes',
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
    SickPayPeriod::class => 'payrollSickPayPeriodAttributes',
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
    AgiUpload::class => 'payrollAgiUploadAttributes',
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
    BoardroomRegister::class => [],
    BoardroomShareClass::class => 'boardroomShareClassAttributes',
    BoardroomShareholder::class => ['kind' => 'PERSON', 'name' => 'Hemlig Ägare', 'personal_number' => '198001011239', 'address_line1' => 'Gatan 1', 'postal_code' => '111 22', 'city' => 'Staden'],
    BoardroomShareEvent::class => 'boardroomShareEventAttributes',
    BoardroomArticlesVersion::class => 'boardroomArticlesAttributes',
    BoardroomMeeting::class => ['kind' => 'BOARD', 'title' => 'Hemligt styrelsemöte', 'meeting_date' => '2026-09-01'],
    BoardroomAgendaItem::class => 'boardroomAgendaItemAttributes',
    BoardroomMinutes::class => 'boardroomMinutesAttributes',
    SaasContractVersion::class => 'saasContractVersionAttributes',
    SaasMonth::class => ['month' => '2026-10-01'],
    SaasCustomerMonth::class => ['month' => '2026-10-01', 'customer_id' => 1, 'mrr_ore' => 99_900, 'state' => 'ACTIVE', 'lines' => []],
    SaasCorrection::class => ['booked_month' => '2026-11-01', 'affects_month' => '2026-10-01', 'customer_id' => 1, 'amount_ore' => -10_000],
    SaasEstimate::class => ['month' => '2026-09-01', 'customer_id' => 1, 'mrr_ore' => 50_000, 'invoice_ids' => []],
    SaasSeparatePair::class => ['crm_agreement_id' => 1, 'recurring_invoice_id' => 'hemligt-avtal'],
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
    StockLocation::class => 'inventoryLocationAttributes',
    StockMovement::class => 'inventoryMovementAttributes',
    ScanOperation::class => 'inventoryScanOperationAttributes',
    StockLevel::class => 'inventoryLevelAttributes',
    StockReceipt::class => 'inventoryReceiptAttributes',
    StockReceiptLine::class => 'inventoryReceiptLineAttributes',
    PickList::class => 'inventoryPickListAttributes',
    PickListLine::class => 'inventoryPickListLineAttributes',
    InvoiceTaxReductionBuyer::class => 'invoiceTaxReductionBuyerAttributes',
    WorkOrder::class => 'workOrderAttributes',
    WorkOrderLabourKind::class => 'workOrderLabourKindAttributes',
    WorkOrderAssignment::class => 'workOrderAssignmentAttributes',
    WorkOrderBuyer::class => 'workOrderBuyerAttributes',
    WorkOrderTimeEntry::class => 'workOrderTimeEntryAttributes',
    WorkOrderMaterialEntry::class => 'workOrderMaterialEntryAttributes',
    WorkOrderChangeOrder::class => 'workOrderChangeOrderAttributes',
    WorkOrderContactAttempt::class => 'workOrderContactAttemptAttributes',
    WorkOrderPaymentInstalment::class => 'workOrderInstalmentAttributes',
    WorkOrderInvoice::class => 'workOrderInvoiceAttributes',
    WorkOrderPhoto::class => 'workOrderPhotoAttributes',
    WorkOrderOnSiteCode::class => 'workOrderOnSiteCodeAttributes',
    StockCount::class => 'inventoryCountAttributes',
    StockCountLine::class => 'inventoryCountLineAttributes',
    StockValuation::class => ['valuation_date' => '2026-12-31', 'method' => 'FIFO', 'cost_value_ore' => 100, 'value_ore' => 100, 'stock_account' => 1460, 'change_account' => 4960, 'lines' => []],
    ArticleImage::class => 'inventoryImageAttributes',
    BillOfMaterials::class => 'structureAttributes',
    BomRow::class => 'structureRowAttributes',
    ArticleUnitConversion::class => 'unitConversionAttributes',
    WasteEntry::class => 'kitchenWasteAttributes',
    WasteMeasurement::class => ['measured_on' => '2026-03-01', 'kg_milli' => 1500],
    StaffMeal::class => ['served_on' => '2026-03-01', 'meal' => 'LUNCH', 'quantity_milli' => 1000, 'reason' => 'REPRESENTATION'],
    MealPayrollProposal::class => 'kitchenProposalAttributes',
    ConsumptionRun::class => 'kitchenRunAttributes',
    SaleConsumption::class => 'kitchenSaleConsumptionAttributes',
    ConsumptionWait::class => 'kitchenWaitAttributes',
    ConsumptionCredit::class => 'kitchenCreditAttributes',
    LabelTemplate::class => ['name' => 'Hemlig etikettmall', 'kind' => 'ARTICLE', 'media' => 'ROLL', 'width_mm' => 50, 'height_mm' => 25, 'layout' => 'AUTO', 'elements' => ['name'], 'is_default' => false],
    CrmAccount::class => ['name' => 'Hemligt prospekt AB'],
    CrmPerson::class => ['name' => 'Hemlig kontakt', 'legal_basis' => 'LEGITIMATE_INTEREST'],
    CrmAccountPerson::class => 'crmAccountPersonAttributes',
    CrmActivity::class => 'crmActivityAttributes',
    CrmSuppression::class => ['kind' => 'EMAIL', 'hash' => 'hemlig-hash', 'reason' => 'OBJECTION'],
    CrmPipeline::class => ['name' => 'Hemlig säljtratt'],
    CrmPipelineStage::class => 'crmPipelineStageAttributes',
    CrmLossReason::class => ['name' => 'Hemlig orsak'],
    CrmDeal::class => 'crmDealAttributes',
    CrmDealStageChange::class => 'crmDealStageChangeAttributes',
    CrmGoal::class => 'crmGoalAttributes',
    CrmCallList::class => ['name' => 'Hemlig ringlista'],
    CrmCallListItem::class => 'crmCallListItemAttributes',
    CrmRegistryNotice::class => 'crmRegistryNoticeAttributes',
    CrmCalendarFeed::class => 'crmCalendarFeedAttributes',
    CrmCalendarConnection::class => 'crmCalendarConnectionAttributes',
    CrmCalendarEvent::class => 'crmCalendarEventAttributes',
    CrmAgreement::class => 'crmAgreementAttributes',
    CrmQuoteTemplate::class => ['name' => 'Hemlig mall', 'validity_days' => 30],
    CrmQuoteTemplateRow::class => 'crmQuoteTemplateRowAttributes',
    CrmSigningRequest::class => 'crmSigningRequestAttributes',
    CrmSigningEvent::class => 'crmSigningEventAttributes',
    // The stored issued invoice PDF and the customer portal (ADR-075).
    InvoiceDocument::class => 'invoiceDocumentAttributes',
    PortalContact::class => 'portalContactAttributes',
    PortalInvitation::class => 'portalInvitationAttributes',
    PortalSettings::class => 'portalSettingsAttributes',
    PortalThread::class => 'portalThreadAttributes',
    PortalMessage::class => 'portalMessageAttributes',
    PortalAttachment::class => 'portalAttachmentAttributes',
    PortalEvent::class => 'portalEventAttributes',
    PortalPaymentNote::class => 'portalPaymentNoteAttributes',
    CrmEmail::class => 'crmEmailAttributes',
    CrmEmailTemplate::class => ['name' => 'Hemlig mall', 'subject' => 'Hemligt ämne', 'body' => 'Hemlig text'],
    CrmSegment::class => ['name' => 'Hemligt segment', 'filters' => []],
    CrmMailing::class => ['name' => 'Hemligt utskick', 'subject' => 'Hemligt ämne', 'body' => 'Hemlig text'],
    CrmMailingLink::class => 'crmMailingLinkAttributes',
    CrmMailingRecipient::class => 'crmMailingRecipientAttributes',
    CrmForm::class => 'crmFormAttributes',
    CrmFormSubmission::class => 'crmFormSubmissionAttributes',
    PosConnection::class => 'posConnectionAttributes',
    PosProductLink::class => 'posProductLinkAttributes',
    PosSale::class => 'posSaleAttributes',
    PosSaleRow::class => 'posSaleRowAttributes',
    PosFee::class => 'posFeeAttributes',
    PosStockPush::class => 'posStockPushAttributes',
    PosWebhookMessage::class => 'posWebhookMessageAttributes',
    PosLogEntry::class => 'posLogEntryAttributes',
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
    ConnectSftpAccount::class => 'connectSftpAccountAttributes',
    ConnectSftpKey::class => 'connectSftpKeyAttributes',
    ConnectSftpUpload::class => 'connectSftpUploadAttributes',
];

function deadlineCalendarFeedAttributes(): array
{
    return ['user_id' => User::factory()->create()->id, 'token_hash' => hash('sha256', bin2hex(random_bytes(16)))];
}

function deadlineReminderAttributes(): array
{
    return ['user_id' => User::factory()->create()->id, 'deadline_key' => 'vat:2026-09-30', 'deadline_date' => '2026-11-12', 'days_before' => 7, 'sent_at' => now()];
}

function companyGuideStepAttributes(): array
{
    return ['step_key' => 'hemligt-steg-'.bin2hex(random_bytes(4)), 'completed_at' => now(), 'completed_by' => User::factory()->create()->id];
}

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

function saasContractVersionAttributes(): array
{
    return [
        'source_type' => 'RECURRING_INVOICE', 'source_id' => (string) Str::uuid(), 'effective_from' => '2026-10-01', 'recorded_at' => '2026-10-01 10:00:00',
        'cause' => 'CREATED', 'status' => 'ACTIVE', 'lines' => [['key' => 'r0', 'description' => 'Hemlig licens', 'currency' => 'SEK', 'amount_minor' => 99_900]], 'sha256' => str_repeat('b', 64),
    ];
}

function boardroomShareClassAttributes(): array
{
    return ['code' => 'H'.random_int(1, 1_000_000), 'name' => 'Hemliga aktier', 'votes_tenths' => 10];
}

function boardroomShareEventAttributes(): array
{
    return ['uuid' => (string) Str::uuid(), 'sequence' => random_int(1, 1_000_000), 'kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'recorded_at' => '2026-03-02 10:00:00', 'share_from' => 1, 'share_to' => 10, 'hash' => str_repeat('a', 64)];
}

function boardroomArticlesAttributes(): array
{
    return ['version' => random_int(1, 1_000_000), 'adopted_on' => '2026-01-01', 'notice_method' => 'Hemlig kallelse'];
}

function boardroomAgendaItemAttributes(): array
{
    return ['meeting_id' => BoardroomMeeting::create(['kind' => 'BOARD', 'title' => 'Hemligt möte', 'meeting_date' => '2026-09-01'])->id, 'position' => 1, 'kind' => 'OTHER', 'title' => 'Hemligt ärende'];
}

function boardroomMinutesAttributes(): array
{
    return ['meeting_id' => BoardroomMeeting::create(['kind' => 'BOARD', 'title' => 'Hemligt möte', 'meeting_date' => '2026-09-01'])->id, 'status' => 'DRAFT', 'body' => ['items' => [], 'notes' => 'Hemligt']];
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

function connectSftpAccountAttributes(): array
{
    return ['username' => 'nnx'.Str::lower(Str::random(13)), 'is_enabled' => true, 'responsible_user_id' => User::factory()->create()->id];
}

/** The company's one SFTP account, made when missing (there is at most one per company). */
function connectSftpAccountId(): int
{
    return (ConnectSftpAccount::query()->first() ?? ConnectSftpAccount::create(connectSftpAccountAttributes()))->id;
}

function connectSftpKeyAttributes(): array
{
    return ['account_id' => connectSftpAccountId(), 'name' => 'Hemlig nyckel', 'algorithm' => 'ssh-ed25519', 'bits' => 256, 'public_key' => 'ssh-ed25519 hemlig', 'fingerprint' => 'SHA256:'.bin2hex(random_bytes(8)), 'created_by' => User::factory()->create()->id];
}

function connectSftpUploadAttributes(): array
{
    return ['account_id' => connectSftpAccountId(), 'event_key' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'file_name' => 'hemlig.se', 'size' => 10, 'status' => 'received'];
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

function registerImportAttributes(): array
{
    return [
        'user_id' => User::factory()->create()->id,
        'kind' => 'customers',
        'original_name' => 'kunder.csv',
        'file_path' => 'register-import/test.csv',
        'size' => 100,
        'sha256' => str_repeat('0', 64),
        'options' => [],
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

function vehicleYearReadingAttributes(): array
{
    return ['vehicle_id' => Vehicle::create(['kind' => 'COMPANY_CAR', 'label' => 'Hemlig bil', 'fuel' => 'PETROL'])->id, 'year' => 2026, 'start_odometer_km' => 10_000];
}

function vehicleTripAttributes(): array
{
    return ['vehicle_id' => Vehicle::create(['kind' => 'COMPANY_CAR', 'label' => 'Hemlig bil', 'fuel' => 'PETROL'])->id, 'trip_date' => '2026-03-02', 'kind' => 'BUSINESS', 'distance_hm' => 420, 'purpose' => 'Hemligt ärende'];
}

function mileageRouteAttributes(): array
{
    return ['user_id' => User::factory()->create()->id, 'label' => 'Hemlig väg', 'distance_hm' => 142];
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

function deferredTaxItemAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'kind' => 'OTHER_ASSET',
        'description' => 'Hemlig byggnad',
        'carrying_ore' => 10_000_000,
        'tax_value_ore' => 6_000_000,
        'tax_value_source' => 'ENTERED',
        'created_by' => User::factory()->create()->id,
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

function k10YearAttributes(): array
{
    return ['income_year' => 2026, 'shares_total' => 100, 'updated_by' => User::factory()->create()->id];
}

function k10OwnerAttributes(): array
{
    return ['income_year' => 2026, 'name' => 'Hemlig ägare', 'identity' => '191212121212', 'shares_owned' => 50, 'updated_by' => User::factory()->create()->id];
}

function incomeTaxPartnerAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'position' => random_int(1, 4),
        'name' => 'Delägare',
        'identity' => '191212121212',
        'legal_person' => false,
        'share_bp' => 5000,
        'updated_by' => User::factory()->create()->id,
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

function paymentResponseImportAttributes(): array
{
    return [
        'format' => 'pain.002',
        'file_name' => 'hemlig.xml',
        'file_path' => 'betalsvar/hemlig.xml',
        'file_sha256' => hash('sha256', (string) random_int(1, 1_000_000)),
        'created_by' => User::factory()->create()->id,
    ];
}

function paymentResponseAttributes(): array
{
    return [
        'payment_response_import_id' => PaymentResponseImport::create(paymentResponseImportAttributes())->id,
        'kind' => 'REJECTED',
        'reference' => 'pain002:'.random_int(1, 1_000_000),
        'end_to_end_id' => 'LF1-1',
        'status' => 'UNMATCHED',
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

/** A made-up GLN per company: the Peppol ID is unique on the installation. */
function peppolParticipantAttributes(): array
{
    $base = '73'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
    $sum = 0;

    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $base[11 - $i] * ($i % 2 === 0 ? 3 : 1);
    }

    return ['identifier' => '0088:'.$base.((10 - $sum % 10) % 10)];
}

function peppolTransmissionAttributes(): array
{
    return [
        'invoice_id' => Invoice::create(invoiceAttributes())->id,
        'document_type' => 'INVOICE',
        'receiver' => '0007:5563343689',
        'status' => 'SENT',
        'driver' => 'log',
        'xml_path' => 'peppol/hemlig.xml',
        'xml_sha256' => str_repeat('a', 64),
        'queued_at' => now(),
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

function payrollSickPayPeriodAttributes(): array
{
    $payslip = Payslip::create(payrollPayslipAttributes());

    return ['payroll_run_id' => $payslip->payroll_run_id, 'payslip_id' => $payslip->id, 'employee_id' => $payslip->employee_id, 'period_start' => '2026-03-02', 'last_day' => '2026-03-03', 'day_count' => 2, 'karens_full_ore' => 100, 'karens_deducted_ore' => 100, 'long_sick_dates' => []];
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

function payrollAgiUploadAttributes(): array
{
    $submission = AgiSubmission::create(payrollAgiSubmissionAttributes());

    return [
        'agi_submission_id' => $submission->id, 'period' => '2026-03', 'upload_id' => random_int(1, 1_000_000_000), 'sha256' => $submission->sha256,
        'status' => 'SAVED', 'uploaded_by' => User::factory()->create()->id, 'uploaded_at' => now(),
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

function crmAccountPersonAttributes(): array
{
    return ['account_id' => CrmAccount::create(['name' => 'Hemligt prospekt AB'])->id, 'person_id' => CrmPerson::create(['name' => 'Hemlig kontakt', 'legal_basis' => 'CONSENT'])->id, 'role' => 'DECISION_MAKER'];
}

function crmActivityAttributes(): array
{
    return ['account_id' => CrmAccount::create(['name' => 'Hemligt prospekt AB'])->id, 'type' => 'NOTE', 'subject' => 'Hemlig anteckning'];
}

function crmPipelineStageAttributes(): array
{
    return ['pipeline_id' => CrmPipeline::create(['name' => 'Hemlig säljtratt'])->id, 'name' => 'Hemligt steg', 'probability' => 40];
}

function crmDealAttributes(): array
{
    $stage = CrmPipelineStage::create(crmPipelineStageAttributes());

    return ['account_id' => CrmAccount::create(['name' => 'Hemligt prospekt AB'])->id, 'pipeline_id' => $stage->pipeline_id, 'stage_id' => $stage->id, 'title' => 'Hemlig affär', 'value_ore' => 100_000];
}

function crmDealStageChangeAttributes(): array
{
    $deal = CrmDeal::create(crmDealAttributes());

    return ['deal_id' => $deal->id, 'to_stage_id' => $deal->stage_id, 'changed_at' => now()];
}

function crmCallListItemAttributes(): array
{
    return ['call_list_id' => CrmCallList::create(['name' => 'Hemlig ringlista'])->id, 'account_id' => CrmAccount::create(['name' => 'Hemligt prospekt AB'])->id, 'position' => 1];
}

function crmRegistryNoticeAttributes(): array
{
    return ['account_id' => CrmAccount::create(['name' => 'Hemligt prospekt AB'])->id, 'registry_change_id' => 1];
}

function crmAgreementAttributes(): array
{
    return ['account_id' => CrmAccount::create(['name' => 'Hemligt prospekt AB'])->id, 'title' => 'Hemligt avtal', 'kind' => 'SERVICE', 'start_date' => '2026-01-01', 'value_period' => 'MONTH'];
}

function crmQuoteTemplateRowAttributes(): array
{
    return ['template_id' => CrmQuoteTemplate::create(['name' => 'Hemlig mall', 'validity_days' => 30])->id, 'description' => 'Hemlig rad', 'quantity' => '1'];
}

function crmSigningRequestAttributes(): array
{
    return ['document_type' => 'AGREEMENT', 'agreement_id' => CrmAgreement::create(crmAgreementAttributes())->id, 'token_hash' => hash('sha256', (string) random_int(0, PHP_INT_MAX)), 'email' => 'hemlig@exempel.se', 'expires_at' => now()->addDays(30), 'document_path' => 'crm-signing/hemlig.pdf', 'document_name' => 'Hemlig.pdf', 'document_sha256' => str_repeat('0', 64)];
}

function workOrderCustomerId(): int
{
    return Customer::query()->firstOrCreate(['number' => 1], ['name' => 'Hemlig kund AB', 'payment_terms_days' => 30])->id;
}

function invoiceTaxReductionBuyerAttributes(): array
{
    return ['invoice_id' => Invoice::create(invoiceAttributes())->id, 'customer_id' => workOrderCustomerId(), 'share_bp' => 5000, 'deduction_ore' => 100];
}

function workOrderAttributes(): array
{
    return ['number' => random_int(1, 1_000_000), 'customer_id' => workOrderCustomerId(), 'title' => 'Hemligt jobb', 'price_form' => 'TIME_AND_MATERIAL', 'access_notes' => 'Hemlig portkod'];
}

function workOrderId(): int
{
    $workOrder = new WorkOrder(workOrderAttributes());
    $workOrder->forceFill(['number' => random_int(1, 1_000_000)])->save();

    return $workOrder->id;
}

function workOrderLabourKindAttributes(): array
{
    return ['code' => 'K'.random_int(1, 1_000_000), 'name' => 'Hemligt arbete', 'type' => 'LABOUR', 'unit' => 'HOUR', 'default_rate_ore' => 0];
}

function workOrderAssignmentAttributes(): array
{
    return ['work_order_id' => workOrderId(), 'user_id' => User::factory()->create()->id];
}

function workOrderBuyerAttributes(): array
{
    return ['work_order_id' => workOrderId(), 'customer_id' => workOrderCustomerId(), 'share_bp' => 5000];
}

function workOrderTimeEntryAttributes(): array
{
    return ['work_order_id' => workOrderId(), 'user_id' => User::factory()->create()->id, 'labour_kind_id' => WorkOrderLabourKind::create(workOrderLabourKindAttributes())->id, 'work_date' => '2026-03-02', 'minutes' => 60, 'rate_ore' => 60_000, 'note' => 'Hemlig anteckning', 'source' => 'WEB', 'entered_at' => now()];
}

function workOrderMaterialEntryAttributes(): array
{
    return ['work_order_id' => workOrderId(), 'user_id' => User::factory()->create()->id, 'description' => 'Hemligt material', 'quantity_milli' => 1000, 'source' => 'FREE', 'work_date' => '2026-03-02', 'entered_at' => now()];
}

function workOrderChangeOrderAttributes(): array
{
    return ['work_order_id' => workOrderId(), 'number' => random_int(1, 1_000_000), 'title' => 'Hemligt ÄTA', 'description' => 'Hemligt', 'basis' => 'CHANGE_ORDERED', 'price_form' => 'FIXED'];
}

function workOrderChangeOrderId(): int
{
    $changeOrder = new WorkOrderChangeOrder(workOrderChangeOrderAttributes());
    $changeOrder->forceFill(['number' => 1])->save();

    return $changeOrder->id;
}

function workOrderContactAttemptAttributes(): array
{
    return ['change_order_id' => workOrderChangeOrderId(), 'attempted_at' => now(), 'channel' => 'PHONE', 'note' => 'Hemligt samtal'];
}

function workOrderInstalmentAttributes(): array
{
    return ['work_order_id' => workOrderId(), 'position' => 0, 'label' => 'Hemlig delbetalning', 'amount_ore' => 100];
}

function workOrderInvoiceAttributes(): array
{
    return ['work_order_id' => workOrderId(), 'invoice_id' => Invoice::create(invoiceAttributes())->id, 'kind' => 'BASIS'];
}

function workOrderPhotoAttributes(): array
{
    return ['work_order_id' => workOrderId(), 'path' => 'arbetsorder/hemlig.jpg', 'caption' => 'Hemlig bild'];
}

function workOrderOnSiteCodeAttributes(): array
{
    return ['change_order_id' => workOrderChangeOrderId(), 'signing_request_id' => CrmSigningRequest::create(crmSigningRequestAttributes())->id, 'code_hash' => str_repeat('0', 64), 'expires_at' => now()->addMinutes(15), 'presented_by' => User::factory()->create()->id];
}

function crmSigningEventAttributes(): array
{
    return ['signing_request_id' => CrmSigningRequest::create(crmSigningRequestAttributes())->id, 'event' => 'SENT'];
}

function invoiceDocumentAttributes(): array
{
    return ['invoice_id' => Invoice::create(invoiceAttributes())->id, 'path' => 'invoices/hemlig.pdf', 'sha256' => str_repeat('0', 64), 'size' => 10];
}

function portalCustomerId(): int
{
    return Customer::query()->firstOrCreate(['number' => 1], ['name' => 'Hemlig kund AB'])->id;
}

function portalContactAttributes(): array
{
    $identity = PortalIdentity::query()->create(['email' => 'hemlig-'.random_int(1, PHP_INT_MAX).'@exempel.se', 'name' => 'Hemlig kontakt']);

    return ['customer_id' => portalCustomerId(), 'portal_identity_id' => $identity->id];
}

function portalInvitationAttributes(): array
{
    return ['contact_id' => PortalContact::create(portalContactAttributes())->id, 'token_sha256' => hash('sha256', (string) random_int(0, PHP_INT_MAX)), 'expires_at' => now()->addDays(14)];
}

function portalSettingsAttributes(): array
{
    return ['portal_key' => PortalSettings::newKey()];
}

function portalThreadAttributes(): array
{
    return ['customer_id' => portalCustomerId(), 'subject' => 'Hemlig fråga', 'last_message_at' => now()];
}

function portalMessageAttributes(): array
{
    return ['thread_id' => PortalThread::create(portalThreadAttributes())->id, 'author_kind' => 'CONTACT', 'body' => 'Hemligt meddelande'];
}

function portalAttachmentAttributes(): array
{
    return ['message_id' => PortalMessage::create(portalMessageAttributes())->id, 'path' => 'portal/hemlig.pdf', 'original_name' => 'hemlig.pdf', 'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('0', 64)];
}

function portalEventAttributes(): array
{
    return ['customer_id' => portalCustomerId(), 'event' => 'VIEWED_STATEMENT'];
}

function portalPaymentNoteAttributes(): array
{
    return ['invoice_id' => Invoice::create(invoiceAttributes())->id, 'paid_on' => '2026-03-15'];
}

function crmGoalAttributes(): array
{
    return ['user_id' => User::factory()->create()->id, 'month' => '2026-06-01', 'metric' => 'MEETINGS', 'target' => 8];
}

function crmCalendarFeedAttributes(): array
{
    return ['user_id' => User::factory()->create()->id, 'token_hash' => hash('sha256', 'hemlig-'.random_int(1, 1_000_000))];
}

function crmCalendarConnectionAttributes(): array
{
    return ['user_id' => User::factory()->create()->id, 'provider' => 'GOOGLE', 'access_token' => 'hemlig-token', 'calendar_id' => 'primary'];
}

function crmCalendarEventAttributes(): array
{
    return [
        'connection_id' => CrmCalendarConnection::create(crmCalendarConnectionAttributes())->id,
        'activity_id' => CrmActivity::create(crmActivityAttributes())->id,
        'external_id' => 'hemlig-handelse',
    ];
}

function crmEmailAttributes(): array
{
    return ['direction' => 'IN', 'status' => 'UNMATCHED', 'channel' => 'MAILGUN', 'subject' => 'Hemligt mejl', 'participants' => ['from' => 'x@y.example', 'from_name' => null, 'to' => [], 'cc' => [], 'other' => []], 'sha256' => hash('sha256', Str::random(20)), 'sent_at' => now()];
}

function crmMailingLinkAttributes(): array
{
    return ['mailing_id' => CrmMailing::create(['name' => 'Hemligt utskick', 'subject' => 'X', 'body' => 'Y'])->id, 'url' => 'https://hemlig.example', 'position' => 1];
}

function crmMailingRecipientAttributes(): array
{
    return ['mailing_id' => CrmMailing::create(['name' => 'Hemligt utskick', 'subject' => 'X', 'body' => 'Y'])->id, 'email' => 'hemlig@mottagare.example', 'token_hash' => hash('sha256', Str::random(40))];
}

function crmFormAttributes(): array
{
    return ['name' => 'Hemligt formulär', 'token' => Str::lower(Str::random(32)), 'fields' => [], 'consent_text' => 'Ja', 'privacy_url' => 'https://x.example/p'];
}

function crmFormSubmissionAttributes(): array
{
    return ['form_id' => CrmForm::create(crmFormAttributes())->id, 'consent_text' => 'Ja', 'consent_version' => 1, 'privacy_url' => 'https://x.example/p', 'consented_at' => now()];
}

function inventoryLocationAttributes(): array
{
    $code = 'H'.random_int(1, 1_000_000);

    return ['type' => 'WAREHOUSE', 'code' => $code, 'full_code' => $code, 'sort_key' => $code, 'name' => 'Hemligt lager'];
}

function inventoryReceiptAttributes(): array
{
    return ['number' => random_int(1, 1_000_000), 'status' => 'OPEN'];
}

function inventoryPickListAttributes(): array
{
    return ['number' => random_int(1, 1_000_000), 'status' => 'OPEN', 'source_label' => 'Hemlig order'];
}

function inventoryCountAttributes(): array
{
    return ['number' => random_int(1, 1_000_000), 'status' => 'OPEN', 'counted_on' => '2026-12-31'];
}

function inventoryImageAttributes(): array
{
    return ['article_id' => Article::create(articleAttributes())->id, 'path' => 'bilder/hemlig.webp', 'position' => 0, 'alt' => 'Hemlig bild'];
}

function inventoryScanOperationAttributes(): array
{
    return ['user_id' => User::factory()->create()->id, 'uuid' => (string) Str::uuid(), 'kind' => 'move', 'status' => 'OK', 'result' => ['status' => 'OK']];
}

function structureAttributes(): array
{
    return ['kind' => 'RECIPE', 'output_article_id' => Article::create(articleAttributes())->id, 'name' => 'Hemligt recept', 'version' => 1, 'valid_from' => '2026-01-01', 'yield_milli' => 1000, 'yield_unit' => 'PORTIONS'];
}

function structureRowAttributes(): array
{
    return ['bill_of_materials_id' => BillOfMaterials::create(structureAttributes())->id, 'component_article_id' => Article::create(articleAttributes())->id, 'quantity_milli' => 100];
}

function unitConversionAttributes(): array
{
    return ['article_id' => Article::create(articleAttributes())->id, 'unit' => 'cl', 'factor_milli' => 70_000];
}

function kitchenWasteAttributes(): array
{
    return ['registered_on' => '2026-03-01', 'kind' => 'INGREDIENT', 'article_id' => Article::create(articleAttributes())->id, 'quantity_milli' => 1000, 'location_id' => StockLocation::create(inventoryLocationAttributes())->id, 'reason' => 'UTGANGET'];
}

function kitchenProposalAttributes(): array
{
    return ['period' => '2026-03', 'employee_id' => Employee::create(payrollEmployeeAttributes())->id, 'pay_code' => 'KOSTFORMAN_LUNCH', 'quantity' => '1', 'details' => [], 'status' => 'PROPOSED'];
}

function kitchenRunAttributes(): array
{
    return ['business_date' => '2026-03-01', 'location_id' => StockLocation::create(inventoryLocationAttributes())->id];
}

function kitchenSaleConsumptionAttributes(): array
{
    return ['pos_sale_row_id' => PosSaleRow::create(posSaleRowAttributes())->id, 'kitchen_consumption_run_id' => ConsumptionRun::create(kitchenRunAttributes())->id];
}

function kitchenCreditAttributes(): array
{
    return ['location_id' => StockLocation::create(inventoryLocationAttributes())->id, 'article_id' => Article::create(articleAttributes())->id, 'quantity_milli' => 100];
}

function kitchenWaitAttributes(): array
{
    return ['business_date' => '2026-03-01', 'location_id' => StockLocation::create(inventoryLocationAttributes())->id, 'article_id' => Article::create(articleAttributes())->id, 'quantity_milli' => 1000, 'kitchen_consumption_run_id' => ConsumptionRun::create(kitchenRunAttributes())->id, 'status' => 'WAITING'];
}

function inventoryMovementAttributes(): array
{
    return ['article_id' => Article::create(articleAttributes())->id, 'location_id' => StockLocation::create(inventoryLocationAttributes())->id, 'quantity_milli' => 1000, 'unit_cost_ore' => 100, 'type' => 'RECEIPT', 'occurred_on' => '2026-03-01'];
}

function inventoryLevelAttributes(): array
{
    return ['article_id' => Article::create(articleAttributes())->id, 'location_id' => StockLocation::create(inventoryLocationAttributes())->id, 'quantity_milli' => 1000];
}

function inventoryReceiptLineAttributes(): array
{
    return ['stock_receipt_id' => StockReceipt::create(['number' => random_int(1, 1_000_000), 'status' => 'OPEN'])->id, 'article_id' => Article::create(articleAttributes())->id, 'ordered_milli' => 1000, 'unit_cost_ore' => 100];
}

function inventoryPickListLineAttributes(): array
{
    return ['pick_list_id' => PickList::create(['number' => random_int(1, 1_000_000), 'status' => 'OPEN'])->id, 'article_id' => Article::create(articleAttributes())->id, 'quantity_milli' => 1000];
}

function inventoryCountLineAttributes(): array
{
    return ['stock_count_id' => StockCount::create(['number' => random_int(1, 1_000_000), 'status' => 'OPEN', 'counted_on' => '2026-12-31'])->id, 'article_id' => Article::create(articleAttributes())->id, 'location_id' => StockLocation::create(inventoryLocationAttributes())->id, 'system_milli' => 0];
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
const NEVER_UPDATED = [WorkOrderContactAttempt::class, VoucherAttachment::class, PosLogEntry::class, CrmDealStageChange::class, CrmSigningEvent::class, ConsumptionRun::class, SaleConsumption::class, PortalInvitation::class, PortalMessage::class, PortalAttachment::class, PortalEvent::class, PortalPaymentNote::class, SaasCustomerMonth::class, SaasEstimate::class];

/** Models that can only be added to: the database refuses bulk changes too. */
const APPEND_ONLY = [WorkOrderTimeEntry::class, WorkOrderMaterialEntry::class, WorkOrderContactAttempt::class, VatNumberCheck::class, TaxAccountPosting::class, AccrualPosting::class, YearEndEntry::class, BankReconciliation::class, FixedAssetDepreciation::class, ImportedVoucher::class, VoucherRowHistory::class, VatReport::class, InvoicePayment::class, InvoiceReminder::class, SupplierPayment::class, SupplierPaymentBatch::class, SupplierPaymentBatchItem::class, BankFileImport::class, BankTransaction::class, PaymentResponseImport::class, PaymentResponse::class, BankFeedTransaction::class, PayrollRuleVerification::class, VacationLiabilitySnapshot::class, VacationYearClosing::class, PayrollYearClosing::class, StaffLedgerEntry::class, AgreementRuleVerification::class, StockMovement::class, StockValuation::class, BoardroomShareEvent::class, BomRow::class, WasteEntry::class, WasteMeasurement::class, StaffMeal::class, InvoiceDocument::class, CrmSigningEvent::class, SaasContractVersion::class, SaasCorrection::class];

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

function posConnectionAttributes(): array
{
    return [
        'provider' => 'zettle', 'status' => 'ACTIVE', 'external_org_id' => 'hemlig-org-'.random_int(1, 1_000_000),
        'credentials' => ['access_token' => 'hemlig-token'], 'webhook_token' => Str::random(48),
        'settings' => [], 'connected_by' => User::factory()->create()->id, 'connected_at' => now(),
    ];
}

function posProductLinkAttributes(): array
{
    return ['pos_connection_id' => PosConnection::create(posConnectionAttributes())->id, 'article_id' => Article::create(articleAttributes())->id, 'external_product_id' => 'hemlig-produkt'];
}

function posSaleAttributes(): array
{
    return [
        'pos_connection_id' => PosConnection::create(posConnectionAttributes())->id, 'provider' => 'zettle', 'external_id' => 'hemligt-kop-'.random_int(1, 1_000_000),
        'source' => 'WEBHOOK', 'sold_at' => now(), 'business_date' => '2026-03-10', 'currency' => 'SEK', 'total_ore' => 12500, 'vat_ore' => 2500,
        'vat_totals' => ['2500' => ['net' => 10000, 'vat' => 2500]], 'payments' => ['CARD' => 12500], 'payload' => ['hemlig' => true], 'payload_hash' => str_repeat('a', 64),
    ];
}

function posSaleRowAttributes(): array
{
    return ['pos_sale_id' => PosSale::create(posSaleAttributes())->id, 'line_no' => 1, 'name' => 'Hemlig vara', 'quantity_milli' => 1000, 'status' => 'UNMATCHED'];
}

function posFeeAttributes(): array
{
    return ['pos_connection_id' => PosConnection::create(posConnectionAttributes())->id, 'external_id' => 'hemlig-avgift', 'type' => 'PAYMENT_FEE', 'business_date' => '2026-03-10', 'amount_ore' => 175, 'payload' => []];
}

function posStockPushAttributes(): array
{
    return [
        'pos_connection_id' => PosConnection::create(posConnectionAttributes())->id, 'stock_movement_id' => StockMovement::create(inventoryMovementAttributes())->id,
        'article_id' => Article::create(articleAttributes())->id, 'delta_milli' => 1000, 'status' => 'PENDING', 'external_uuid' => (string) Str::uuid(),
    ];
}

function posWebhookMessageAttributes(): array
{
    return ['pos_connection_id' => PosConnection::create(posConnectionAttributes())->id, 'message_id' => 'hemligt-meddelande', 'event_name' => 'PurchaseCreated', 'body' => '{}'];
}

function posLogEntryAttributes(): array
{
    return ['pos_connection_id' => PosConnection::create(posConnectionAttributes())->id, 'level' => 'info', 'message' => 'Hemlig händelse', 'created_at' => now()];
}
