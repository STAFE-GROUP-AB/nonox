<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notice to the people responsible for a company's payroll when
 * something the whole installation shares has changed: Skatteverket's tax
 * tables for a year were imported, or new or changed payroll values arrived
 * that someone must check before a run may use them. The sentence carries
 * what to check; the notice opens Lön → Regler in that company. Stored in
 * the database only, like other notices.
 */
class PayrollYearNotice extends Notification
{
    public const string TAX_TABLES = 'payroll_tax_tables';

    public const string RULES = 'payroll_rules';

    public function __construct(
        public readonly string $kind,
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly int $year,
        public readonly string $title,
    ) {}

    public static function taxTables(int $companyId, string $companyName, int $year): self
    {
        return new self(self::TAX_TABLES, $companyId, $companyName, $year, __('Skatteverkets skattetabeller för :year är inlästa. Gå igenom de anställda i :company före första lönen :year: kontrollera att skattetabell och kolumn stämmer med var och ens nya skattebesked, och att engångsskatt och SINK är rätt.', ['year' => $year, 'company' => $companyName]));
    }

    public static function rules(int $companyId, string $companyName, int $year, int $count): self
    {
        return new self(self::RULES, $companyId, $companyName, $year, trans_choice('{1} Ett lönevärde för :year är nytt eller ändrat. Kontrollera det mot källan under Lön → Regler i :company. En lönekörning som behöver det stoppas tills det är kontrollerat.|[2,*] :count lönevärden för :year är nya eller ändrade. Kontrollera dem mot källan under Lön → Regler i :company. En lönekörning som behöver dem stoppas tills de är kontrollerade.', $count, ['count' => $count, 'year' => $year, 'company' => $companyName]));
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
            'kind' => $this->kind,
            'company_id' => $this->companyId,
            'company_name' => $this->companyName,
            'year' => $this->year,
            'title' => $this->title,
            'destination' => 'payroll-rules',
        ];
    }
}
