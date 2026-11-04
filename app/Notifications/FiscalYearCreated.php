<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Company;
use App\Models\FiscalYear;
use Illuminate\Notifications\Notification;

/**
 * An in-app notice that NONOX created the next fiscal year by itself
 * (CreateNextFiscalYear::dueFor or ::covering), to the owner and everyone
 * who may manage fiscal years. Stored in the database only; it opens
 * Räkenskapsår in that company. Sent once per person and year.
 */
class FiscalYearCreated extends Notification
{
    public const string KIND = 'fiscal_year_created';

    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly int $fiscalYearId,
        public readonly string $title,
    ) {}

    public static function for(Company $company, FiscalYear $fiscalYear): self
    {
        return new self(
            $company->id,
            (string) $company->name,
            $fiscalYear->id,
            __('Räkenskapsåret :period är skapat. Det förra året stängs fortfarande i bokslutet.', ['period' => $fiscalYear->label()]),
        );
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => self::KIND,
            'company_id' => $this->companyId,
            'company_name' => $this->companyName,
            'fiscal_year_id' => $this->fiscalYearId,
            'title' => $this->title,
            'destination' => 'fiscal-years',
            'destination_label' => __('Öppna Räkenskapsår'),
        ];
    }
}
