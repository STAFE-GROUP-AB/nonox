<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Company\SetDimensionScope;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;

/**
 * A company with two cost centres (10 Butik Norr, 20 Butik Syd) and one
 * project (P1 Ombyggnad), vouchers and supplier invoices inside and outside
 * a scope of "10 + P1", and a member limited to that scope (ADR-081 gap 10).
 *
 * Inside: rows on 10, rows on P1 (whatever their cost centre). Outside: rows
 * on 20 only, a mix of 10 and 20, and no dimension at all.
 */
final class DimensionScopeFixture
{
    public User $owner;

    public Company $company;

    public CostCenter $north;

    public CostCenter $south;

    public Project $rebuild;

    public Supplier $supplier;

    /** @var array<string, Voucher> inside, project, outside, mixed, none */
    public array $vouchers = [];

    /** @var array<string, SupplierInvoice> inside, outside */
    public array $invoices = [];

    public static function make(): self
    {
        $fixture = new self;
        [$fixture->owner, $fixture->company] = Bookkeeping::company();

        CurrentCompany::run($fixture->company, function () use ($fixture): void {
            $fixture->north = CostCenter::create(['code' => '10', 'name' => 'Butik Norr']);
            $fixture->south = CostCenter::create(['code' => '20', 'name' => 'Butik Syd']);
            $fixture->rebuild = Project::create(['code' => 'P1', 'name' => 'Ombyggnad']);

            $fixture->vouchers = [
                'inside' => $fixture->post('Kassa norr', [['account' => 1930, 'debit_ore' => 50_000], ['account' => 3001, 'credit_ore' => 50_000, 'cost_center' => '10']]),
                'project' => $fixture->post('Material ombyggnad', [['account' => 4010, 'debit_ore' => 20_000, 'cost_center' => '20', 'project' => 'P1'], ['account' => 1930, 'credit_ore' => 20_000]]),
                'outside' => $fixture->post('Kassa syd', [['account' => 1930, 'debit_ore' => 70_000], ['account' => 3001, 'credit_ore' => 70_000, 'cost_center' => '20']]),
                'mixed' => $fixture->post('Hyra delad', [['account' => 5010, 'debit_ore' => 30_000, 'cost_center' => '10'], ['account' => 5010, 'debit_ore' => 30_000, 'cost_center' => '20'], ['account' => 1930, 'credit_ore' => 60_000]]),
                'none' => $fixture->post('Bankavgift', [['account' => 6570, 'debit_ore' => 5_000], ['account' => 1930, 'credit_ore' => 5_000]]),
            ];

            $fixture->supplier = app(SaveSupplier::class)($fixture->owner, $fixture->company, [
                'name' => 'Grossisten AB', 'bankgiro' => '50501055', 'payment_terms_days' => 30, 'default_account' => 4010,
            ]);

            $fixture->invoices = [
                'inside' => $fixture->supplierInvoice('F-1', '10'),
                'outside' => $fixture->supplierInvoice('F-2', '20'),
            ];
        });

        return $fixture;
    }

    /** A member with the role, limited to cost centre 10 and project P1 unless told otherwise. */
    public function member(string $role = 'bookkeeper', ?array $costCenters = null, ?array $projects = null): User
    {
        $member = Bookkeeping::member($this->company, $role);

        app(SetDimensionScope::class)($this->owner, $this->company, $member->id, $costCenters ?? [$this->north->id], $projects ?? [$this->rebuild->id]);
        DimensionScope::forget();

        return $member->fresh();
    }

    /** @param list<array<string, mixed>> $rows */
    private function post(string $description, array $rows): Voucher
    {
        return app(PostVoucher::class)($this->owner, app(SaveDraftVoucher::class)($this->owner, $this->company, [
            'voucher_date' => '2026-03-01',
            'description' => $description,
            'series' => 'A',
            'rows' => $rows,
        ]));
    }

    private function supplierInvoice(string $number, string $costCenter): SupplierInvoice
    {
        return app(SaveSupplierInvoice::class)($this->owner, $this->company, [
            'supplier_id' => $this->supplier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-03-05',
            'total' => '1 250,00',
            'vat' => '250,00',
            'rows' => [['account' => '4010', 'amount' => '1000', 'description' => 'Varor', 'cost_center' => $costCenter]],
        ]);
    }
}
