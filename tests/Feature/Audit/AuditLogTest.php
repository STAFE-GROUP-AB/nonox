<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\Company\UpdateCompanyDetails;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Database\Factories\CompanyFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;

/*
 * Payloads are stored as jsonb, which does not keep the key order, so payloads
 * are compared with toEqual.
 */

/**
 * Runs a statement in a savepoint so the trigger's error does not abort the
 * surrounding test transaction, and returns whether it was rejected.
 */
function rejectedByDatabase(callable $statement): bool
{
    try {
        DB::transaction($statement);
    } catch (QueryException $exception) {
        // The trigger refuses; with the database roles (ADR-079 §6) the grant refuses first.
        return str_contains($exception->getMessage(), 'audit_logs is append-only')
            || str_contains($exception->getMessage(), 'permission denied for table audit_logs');
    }

    return false;
}

it('records who did what to what, in which company', function () {
    $user = User::factory()->withCompany()->create();
    $company = $user->currentCompany();
    $this->actingAs($user);

    $entry = Audit::record('test.action', $company, ['key' => 'value'], $company);

    expect($entry->fresh())
        ->company_id->toBe($company->id)
        ->user_id->toBe($user->id)
        ->action->toBe('test.action')
        ->subject_type->toBe(Company::class)
        ->subject_id->toBe((string) $company->id)
        ->payload->toBe(['key' => 'value'])
        ->created_at->not->toBeNull();
});

it('refuses to change or delete entries through Eloquent', function () {
    $entry = Audit::record('test.action');

    expect(fn () => $entry->update(['action' => 'forged']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and($entry->fresh()->action)->toBe('test.action');
});

it('refuses to change or delete entries in the database', function () {
    $entry = Audit::record('test.action');

    expect(rejectedByDatabase(fn () => AuditLog::query()->whereKey($entry->id)->update(['action' => 'forged'])))->toBeTrue()
        ->and(rejectedByDatabase(fn () => AuditLog::query()->whereKey($entry->id)->delete()))->toBeTrue()
        ->and(rejectedByDatabase(fn () => DB::update('update audit_logs set action = ? where id = ?', ['forged', $entry->id])))->toBeTrue()
        ->and(rejectedByDatabase(fn () => DB::delete('delete from audit_logs where id = ?', [$entry->id])))->toBeTrue()
        ->and(rejectedByDatabase(fn () => DB::statement('truncate audit_logs cascade')))->toBeTrue()
        ->and(AuditLog::find($entry->id)?->action)->toBe('test.action');
});

it('records the creation of a company with the person who created it', function () {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->create($user, [
        'name' => 'Loggat AB',
        'org_number' => CompanyFactory::organisationNumber()->digits,
        'legal_form' => 'AB',
        'fiscal_year_start_month' => 1,
        'accounting_method' => 'FAKTURAMETODEN',
        'vat_registered' => true,
        'vat_period' => 'QUARTERLY',
        'reporting_framework' => 'K2',
        'framework_confirmed' => true,
        'f_skatt' => true,
        'employer_registered' => false,
    ]);

    $entry = AuditLog::forCompany($company)->where('action', 'company.created')->sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->payload)->toMatchArray(['name' => 'Loggat AB', 'org_number' => $company->org_number]);
});

it('records only the details that changed, before and after', function () {
    $owner = User::factory()->withCompany(fn ($f) => $f->state(['city' => 'Malmö', 'name' => 'Före AB']))->create();
    $company = $owner->currentCompany();
    $this->actingAs($owner);

    $input = [
        'name' => 'Före AB', 'address_line1' => null, 'postal_code' => null, 'city' => 'Lund',
        'email' => null, 'phone' => null, 'website' => null, 'bankgiro' => null, 'plusgiro' => null,
        'vat_period' => $company->vat_period->value, 'fiscal_year_start_month' => 1,
        'accounting_method' => $company->accounting_method->value,
        'reporting_framework' => $company->reporting_framework->value,
        'f_skatt' => $company->f_skatt, 'employer_registered' => $company->employer_registered,
    ];

    app(UpdateCompanyDetails::class)($owner, $company, $input);
    app(UpdateCompanyDetails::class)($owner, $company->fresh(), $input);

    $entries = AuditLog::forCompany($company)->where('action', 'company.updated')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->sole()->payload)->toEqual(['before' => ['city' => 'Malmö'], 'after' => ['city' => 'Lund']])
        ->and($entries->sole()->user_id)->toBe($owner->id);
});

it('records switching company', function () {
    $user = User::factory()->withCompany()->create();
    $second = Company::factory()->create(['user_id' => $user->id]);
    $first = $user->current_team_id;
    $this->actingAs($user = $user->fresh());

    $user->switchCompany($second);

    expect(AuditLog::forCompany($second)->where('action', 'company.switched')->sole()->payload)
        ->toBe(['from_company_id' => $first]);
});

it('records members joining, changing role and leaving', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $member = User::factory()->create(['email' => 'kollega@example.com']);
    $this->actingAs($owner);

    app(AddsTeamMembers::class)->add($owner, $company, 'kollega@example.com', 'bookkeeper');
    app(UpdateTeamMemberRole::class)->update($owner, $company->fresh(), $member->id, 'auditor');
    app(RemovesTeamMembers::class)->remove($owner, $company->fresh(), $member);

    $actions = AuditLog::forCompany($company)
        ->whereIn('action', ['company.member_added', 'company.member_role_changed', 'company.member_removed'])
        ->orderBy('id')
        ->get(['action', 'payload']);

    expect($actions->pluck('action')->all())->toBe(['company.member_added', 'company.member_role_changed', 'company.member_removed'])
        ->and($actions[0]->payload)->toEqual(['email' => 'kollega@example.com', 'role' => 'bookkeeper'])
        ->and($actions[1]->payload)->toEqual(['email' => 'kollega@example.com', 'role' => 'auditor']);
});

it('records invitations', function () {
    Mail::fake();
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $this->actingAs($owner);

    app(InvitesTeamMembers::class)->invite($owner, $company, 'ny@example.com', 'accountant');

    expect(AuditLog::forCompany($company)->where('action', 'company.invitation_sent')->sole()->payload)
        ->toEqual(['email' => 'ny@example.com', 'role' => 'accountant']);
});

it('records signing in and out', function () {
    $user = User::factory()->withCompany()->create();

    auth()->login($user);
    auth()->logout();

    expect(AuditLog::where('user_id', $user->id)->whereIn('action', ['auth.signed_in', 'auth.signed_out'])->orderBy('id')->pluck('action')->all())
        ->toBe(['auth.signed_in', 'auth.signed_out']);
});

it('only shows a company its own entries', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();

    Audit::record('test.a', company: $a);
    Audit::record('test.b', company: $b);

    expect(AuditLog::forCompany($a)->where('action', 'like', 'test.%')->pluck('action')->all())->toBe(['test.a']);
});
