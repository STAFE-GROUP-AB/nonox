<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Models\AuditLog;
use App\Models\ClientProfile;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the accounting firm has done in a client company, for the client:
 * the responsible consultant and, day by day, what the company's members
 * with the Redovisningskonsult role did, counted from the audit trail.
 * Three queries, whatever the number of entries.
 */
final class FirmActivity
{
    public const int DAYS = 60;

    /** What an audit action means, in the client's words. Others count as "Övriga ändringar". */
    private const array ACTIONS = [
        'voucher.posted' => 'Bokförde verifikationer',
        'voucher.corrected' => 'Rättade verifikationer',
        'voucher.attachment_added' => 'La till underlag',
        'invoice.issued' => 'Utfärdade kundfakturor',
        'invoice.sent' => 'Skickade kundfakturor',
        'invoice.payment_registered' => 'Registrerade inbetalningar',
        'invoice.reminder_sent' => 'Skickade påminnelser',
        'supplier_invoice.registered' => 'Registrerade leverantörsfakturor',
        'supplier_invoice.paid' => 'Registrerade betalningar till leverantörer',
        'payment_batch.created' => 'Skapade betalfiler',
        'bank_file.imported' => 'Läste in bankfiler',
        'vat.settled' => 'Bokförde momsdeklarationen',
        'vat.file_downloaded' => 'Hämtade momsfilen till Skatteverket',
        'period.locked' => 'Låste bokföringen',
        'fiscal_year.created' => 'Skapade räkenskapsår',
        'fiscal_year.balances_carried_forward' => 'Förde över balanser till nästa år',
        'sie.exported' => 'Exporterade SIE-fil',
        'sie.imported' => 'Läste in SIE-fil',
        'archive.package_created' => 'Skapade arkivpaket',
        'payroll.run_approved' => 'Attesterade lönekörningar',
        'payroll.run_posted' => 'Bokförde löner',
        'payroll.tax_posted' => 'Bokförde skatt och arbetsgivaravgifter',
        'payroll.agi_generated' => 'Skapade arbetsgivardeklarationer',
        'payroll.agi_submitted' => 'Lämnade arbetsgivardeklarationer',
        'payroll.expense_approved' => 'Godkände utlägg',
        'account.created' => 'Ändrade kontoplanen',
        'account.updated' => 'Ändrade kontoplanen',
    ];

    /** Signing in and similar entries say nothing about the work. */
    private const array IGNORED = ['auth.signed_in', 'auth.signed_out', 'company.switched', 'company.client_note_changed', 'company.responsible_changed'];

    public static function visibleTo(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'audit-log:view')
            && DB::table('team_user')->where('team_id', $company->id)->where('user_id', $user->id)->value('role') !== ClientProfile::FIRM_ROLE;
    }

    /**
     * @return array{
     *     responsible: ?string,
     *     consultants: list<string>,
     *     days: list<array{date: string, entries: list<array{name: string, label: string, count: int}>}>
     * }|null null when no member of the company is a consultant
     */
    public static function for(Company $company, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::today('Europe/Stockholm');
        $consultants = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')
            ->where('team_user.team_id', $company->id)->where('team_user.role', ClientProfile::FIRM_ROLE)
            ->orderBy('users.name')->pluck('users.name', 'users.id')->map(fn (mixed $name): string => (string) $name)->all();

        if ($consultants === []) {
            return null;
        }

        $responsibleId = DB::table('client_profiles')->where('company_id', $company->id)->value('responsible_user_id');

        // Timestamps are stored in the application's time zone (Europe/Stockholm), so the date is the local day.
        $rows = AuditLog::query()->forCompany($company)
            ->whereIn('user_id', array_keys($consultants))
            ->whereNotIn('action', self::IGNORED)
            ->where('created_at', '>=', $today->subDays(self::DAYS)->startOfDay())
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, user_id, action, count(*) as aggregate")
            ->groupByRaw('1, 2, 3')
            ->orderByRaw('1 desc')
            ->get();

        $days = [];

        foreach ($rows as $row) {
            $day = (string) $row->getAttribute('day');
            $name = $consultants[(int) $row->user_id] ?? '';
            $label = __(self::ACTIONS[$row->action] ?? 'Övriga ändringar');
            $key = "{$name}|{$label}";
            $days[$day][$key] = [
                'name' => $name,
                'label' => is_string($label) ? $label : $row->action,
                'count' => ($days[$day][$key]['count'] ?? 0) + (int) $row->getAttribute('aggregate'),
            ];
        }

        return [
            'responsible' => $responsibleId === null ? null : ($consultants[(int) $responsibleId] ?? null),
            'consultants' => array_values($consultants),
            'days' => array_map(fn (string $date, array $entries): array => ['date' => $date, 'entries' => array_values($entries)], array_keys($days), array_values($days)),
        ];
    }
}
