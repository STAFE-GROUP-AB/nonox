<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Documents\AddDocumentVersion;
use App\Actions\Documents\LinkDocument;
use App\Actions\Documents\SetLegalHold;
use App\Actions\Documents\StoreDocument;
use App\Domain\Documents\LegalHoldReason;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Supplier;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;

/**
 * Dokument (ADR-081 gap 9, E19) for the manual's document archive pages:
 * the consultancy's lease, insurance, a policy, a permit-like certificate
 * and a receipt filed as räkenskapsinformation. Every file is a made-up PDF
 * rendered here; nothing comes from a real company.
 */
final class DocumentsExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Dokument', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            if (Document::query()->exists()) {
                return;
            }

            $store = app(StoreDocument::class);
            $year = $c->today->year;

            $lease = $store($c->user, $c->company, [
                'title' => 'Hyresavtal kontor Storgatan',
                'category' => 'AGREEMENT',
                'retention_class' => 'CONTRACT',
                'contract_ends_on' => ($year + 2).'-09-30',
                'contract_retention_years' => '2',
                'contains_personal_data' => '1',
                'tags' => 'lokal, hyra',
            ], $this->pdf('Hyresavtal-kontor.pdf', 'Hyresavtal för kontorslokal', 'Hyresvärden upplåter kontorslokal om 120 kvadratmeter till hyresgästen. Avtalet löper i tre år med nio månaders uppsägningstid.'));
            app(AddDocumentVersion::class)($c->user, $lease, $this->pdf('Hyresavtal-kontor-tillagg.pdf', 'Hyresavtal med tillägg', 'Tillägg 1: hyresgästen hyr även ett förråd om 8 kvadratmeter från och med första januari.'));

            $supplier = Supplier::query()->orderBy('number')->first();

            if ($supplier instanceof Supplier) {
                app(LinkDocument::class)($c->user, $lease, 'supplier', $supplier->id);
            }

            $store($c->user, $c->company, [
                'title' => 'Företagsförsäkring '.$year,
                'category' => 'INSURANCE',
                'retention_class' => 'CONTRACT',
                'contract_ends_on' => $year.'-12-31',
                'contract_retention_years' => '3',
                'contains_personal_data' => '0',
                'tags' => 'försäkring, ansvar',
            ], $this->pdf('Forsakringsbrev.pdf', 'Försäkringsbrev', 'Företagsförsäkring med egendom, avbrott och ansvar för konsultverksamhet. Självrisk ett prisbasbelopp.'));

            $framework = $store($c->user, $c->company, [
                'title' => 'Ramavtal konsulttjänster',
                'category' => 'AGREEMENT',
                'retention_class' => 'CONTRACT',
                'contains_personal_data' => '1',
                'tags' => 'ramavtal',
            ], $this->pdf('Ramavtal.pdf', 'Ramavtal om konsulttjänster', 'Ramavtalet gäller tills vidare med tre månaders uppsägning. Timpris enligt bilaga.'));

            $customer = Customer::query()->orderBy('number')->first();

            if ($customer instanceof Customer) {
                app(LinkDocument::class)($c->user, $framework, 'customer', $customer->id);
            }

            $store($c->user, $c->company, [
                'title' => 'Policy för attest och utlägg',
                'category' => 'POLICY',
                'retention_class' => 'OTHER',
                'contains_personal_data' => '0',
                'tags' => 'rutin',
            ], $this->pdf('Attestpolicy.pdf', 'Policy för attest och utlägg', 'Varje leverantörsfaktura attesteras av en annan person än den som registrerade den. Utlägg redovisas med kvitto inom en månad.'));

            $receipt = $store($c->user, $c->company, [
                'title' => 'Årsavgift bredband, faktura',
                'category' => 'ACCOUNTING',
                'retention_class' => 'ACCOUNTING',
                'fiscal_year_id' => (string) $c->fiscalYear->id,
                'contains_personal_data' => '0',
                'tags' => 'kvitto',
            ], $this->pdf('Faktura-bredband.pdf', 'Faktura bredband', 'Årsavgift för fiberanslutning till kontoret, 12 månader.'));

            app(SetLegalHold::class)($c->user, $receipt, true, LegalHoldReason::TaxAudit);
        }));
    }

    private function pdf(string $name, string $heading, string $text): UploadedFile
    {
        $pdf = new Dompdf;
        $pdf->loadHtml('<h1 style="font-family: DejaVu Sans">'.e($heading).'</h1><p style="font-family: DejaVu Sans">'.e($text).'</p><p style="font-family: DejaVu Sans">Exempeldokument för demonstration.</p>');
        $pdf->render();

        $path = (string) tempnam(sys_get_temp_dir(), 'nonox-demo-document');
        file_put_contents($path, (string) $pdf->output());
        register_shutdown_function(fn () => @unlink($path));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
