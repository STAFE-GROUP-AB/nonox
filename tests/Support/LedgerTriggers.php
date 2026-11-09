<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * The immutability triggers on the ledger, as the migrations define them
 * (2026_10_29_160000_create_vouchers_tables). TriggerBypassGapTest disables,
 * drops and replaces them inside rolled-back transactions; these checks prove
 * none of that is ever committed.
 */
final class LedgerTriggers
{
    /** Trigger name => pg_get_triggerdef(): function, events, timing and level. */
    public const array DEFINITIONS = [
        'voucher_rows_no_truncate' => 'CREATE TRIGGER voucher_rows_no_truncate BEFORE TRUNCATE ON public.voucher_rows FOR EACH STATEMENT EXECUTE FUNCTION ledger_no_truncate()',
        'voucher_rows_protect_posted' => 'CREATE TRIGGER voucher_rows_protect_posted BEFORE INSERT OR DELETE OR UPDATE ON public.voucher_rows FOR EACH ROW EXECUTE FUNCTION voucher_rows_protect_posted()',
        'vouchers_check_balance_on_post' => 'CREATE TRIGGER vouchers_check_balance_on_post BEFORE INSERT OR UPDATE ON public.vouchers FOR EACH ROW EXECUTE FUNCTION vouchers_check_balance_on_post()',
        'vouchers_no_truncate' => 'CREATE TRIGGER vouchers_no_truncate BEFORE TRUNCATE ON public.vouchers FOR EACH STATEMENT EXECUTE FUNCTION ledger_no_truncate()',
        'vouchers_protect_posted' => 'CREATE TRIGGER vouchers_protect_posted BEFORE DELETE OR UPDATE ON public.vouchers FOR EACH ROW EXECUTE FUNCTION vouchers_protect_posted()',
    ];

    /**
     * Function name => md5 of its body (pg_proc.prosrc). Update these only
     * together with a migration that redefines the function.
     */
    public const array FUNCTION_BODIES = [
        'ledger_no_truncate' => '70e6b9480ca8cf0d09fdd19df511cb73',
        'voucher_rows_protect_posted' => 'e5d6130e9bc9ce793b6a6a6a4bf7414f',
        'vouchers_check_balance_on_post' => '46a4a87f76d42a7cd17ddfd06ef2cf92',
        'vouchers_protect_posted' => '8b900c95e30894f8a1db11e7ec907b46',
    ];

    /**
     * The triggers and functions as the connection sees them.
     *
     * @return array{triggers: array<string, string>, enabled: array<string, string>, functions: array<string, string>}
     */
    public static function state(?string $connection = null): array
    {
        $rows = DB::connection($connection)->select(<<<'SQL'
            select tgname, tgenabled, pg_get_triggerdef(oid) as definition from pg_trigger
            where not tgisinternal and tgrelid in ('vouchers'::regclass, 'voucher_rows'::regclass)
            order by tgname
            SQL);

        $functions = DB::connection($connection)->select(
            'select proname, md5(prosrc) as body from pg_proc where proname in (?, ?, ?, ?) order by proname',
            array_keys(self::FUNCTION_BODIES),
        );

        return [
            'triggers' => collect($rows)->mapWithKeys(fn (object $row): array => [$row->tgname => $row->definition])->all(),
            'enabled' => collect($rows)->mapWithKeys(fn (object $row): array => [$row->tgname => $row->tgenabled])->all(),
            'functions' => collect($functions)->mapWithKeys(fn (object $row): array => [$row->proname => $row->body])->all(),
        ];
    }

    /**
     * The state the migrations leave.
     *
     * @return array{triggers: array<string, string>, enabled: array<string, string>, functions: array<string, string>}
     */
    public static function expected(): array
    {
        return [
            'triggers' => self::DEFINITIONS,
            'enabled' => array_map(fn (): string => 'O', self::DEFINITIONS),
            'functions' => self::FUNCTION_BODIES,
        ];
    }

    /**
     * The committed state, read on a second connection that does not share
     * the test's open transaction. Only catalog rows are read, so the
     * ACCESS EXCLUSIVE locks the test holds on the tables do not block it;
     * the timeout stops the run rather than hanging if that ever changes.
     *
     * @return array{triggers: array<string, string>, enabled: array<string, string>, functions: array<string, string>}
     */
    public static function committedState(): array
    {
        $name = 'ledger_trigger_check';
        config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);

        try {
            DB::connection($name)->statement("set statement_timeout = '10s'");

            return self::state($name);
        } finally {
            DB::purge($name);
        }
    }
}
