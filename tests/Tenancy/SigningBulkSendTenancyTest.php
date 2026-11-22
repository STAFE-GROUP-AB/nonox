<?php

declare(strict_types=1);

use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\ManageSigningBulkSends;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Actions\ProcessSigningBulkSend;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\BulkSendPage;
use App\Modules\Signing\Livewire\BulkSendsPage;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Modules\Signing\Models\SigningBulkSendRow;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;

/*
 * AGENTS.md §13.2 for bulk send (ADR-046 amendment, gap 8, S15): company A
 * never sees B's runs or rows, finds none by id (404, not 403), cannot
 * confirm, cancel or run one, cannot start one from B's template, and the
 * database refuses a run or a row that points at another company.
 */

/** @return array{0: User, 1: Company, 2: SigningTemplate, 3: SigningBulkSend} */
function s15TenancyRun(string $name): array
{
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    app(ModuleManager::class)->enable(SigningModule::KEY, $company, $owner);

    $path = tempnam(sys_get_temp_dir(), 's15t');
    file_put_contents($path, PdfEngineFixtures::pdf(1));
    $document = app(StoreSigningDocument::class)($owner, $company, $path, 'Avtal.pdf', $name);
    @unlink($path);

    $template = app(ManageSigningTemplates::class)->create($owner, $company, [
        'name' => $name,
        'roles' => [['label' => 'Kund', 'step' => 1]],
        'fields' => [['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => "{$name} kund"]],
    ], $document);

    $file = tempnam(sys_get_temp_dir(), 's15csv');
    file_put_contents($file, "Kund e-post\n".strtolower($name)."@example.test\n");
    $run = app(ManageSigningBulkSends::class)->prepare($owner, $company, $template, $file);
    @unlink($file);

    return [$owner, $company, $template, $run];
}

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    Queue::fake();
    FakePdfEngine::install();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    [$this->ownerA, $this->companyA, $this->templateA, $this->runA] = s15TenancyRun('Alfa');
    [$this->ownerB, $this->companyB, $this->templateB, $this->runB] = s15TenancyRun('Beta');

    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA->refresh());
});

it('shows a company only its own runs and rows', function () {
    expect(SigningBulkSend::query()->pluck('id')->all())->toBe([$this->runA->id])
        ->and(SigningBulkSendRow::query()->pluck('bulk_send_id')->unique()->all())->toBe([$this->runA->id])
        ->and(SigningBulkSend::query()->find($this->runB->id))->toBeNull();

    Livewire::test(BulkSendsPage::class)->assertSee('Alfa')->assertDontSee('Beta');
    $this->get(route('signing.bulk-sends'))->assertOk()->assertSee('Alfa')->assertDontSee('Beta');
});

it('answers 404 for another company\'s run, not 403', function () {
    $this->get(route('signing.bulk-send', $this->runB->id))->assertNotFound();
    Livewire::test(BulkSendPage::class, ['bulkSend' => $this->runB->id])->assertNotFound();
    $this->get(route('signing.bulk-sends.example', $this->templateB->id))->assertNotFound();
});

it('refuses to confirm, cancel or run another company\'s run, and to start one from its template', function () {
    $runs = app(ManageSigningBulkSends::class);

    expect(fn () => $runs->confirm($this->ownerA, $this->companyA, $this->runB))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $runs->cancel($this->ownerA, $this->companyA, $this->runB))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $runs->prepare($this->ownerA, $this->companyA, $this->templateB, tempnam(sys_get_temp_dir(), 'x')))->toThrow(NotFoundHttpException::class);

    // Company B's run, confirmed by its own owner, is worked inside company B only.
    CurrentCompany::set($this->companyB);
    $runs->confirm($this->ownerB, $this->companyB, $this->runB);
    CurrentCompany::set($this->companyA);
    app(ProcessSigningBulkSend::class)($this->companyB->id, $this->runB->id);

    expect(SigningBulkSend::query()->find($this->runA->id)->status)->toBe('PREVIEW')
        ->and(CurrentCompany::run($this->companyB, fn () => SigningEnvelope::query()->count()))->toBe(1)
        ->and(SigningEnvelope::query()->count())->toBe(0);
});

it('has the database refuse a run or a row that points at another company', function () {
    expect(fn () => DB::transaction(fn () => DB::table('signing_bulk_sends')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->companyA->id, 'template_id' => $this->templateB->id, 'status' => 'PREVIEW'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_bulk_send_rows')->insert(['company_id' => $this->companyA->id, 'bulk_send_id' => $this->runB->id, 'row_number' => 99, 'status' => 'INVALID'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_bulk_sends')->where('id', $this->runA->id)->update(['company_id' => $this->companyB->id])))->toThrow(QueryException::class);
});
