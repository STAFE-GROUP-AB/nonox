<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Signing\ManageSealCertificates;
use App\Actions\Signing\RecordSigningConsent;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Seal\SelfSignedSeal;
use App\Domain\Signing\SigningConsent;
use App\Domain\Signing\SigningPdfs;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\ManageSigningBulkSends;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Modules\Signing\Models\SigningBulkSendRow;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The module Signering in the consultancy (ADR-046 amendment, gap 10, package
 * S7), for the manual's pages on the envelope list, the envelope page and the
 * send wizard: three standalone documents, a signed non-disclosure agreement,
 * a lease out to two signers in order (the first has signed), and an
 * employment contract that is confidential, and the company's own seal
 * certificate (S10, made at seeding). The PDFs are made here with dompdf
 * and stored the way an upload is, without the PDF engine (the demo needs no
 * Python). Every name and address is made up; e-mail addresses end in .test.
 */
final class SigningDocumentsExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Signering: egna dokument', function () use ($c): void {
            app(ModuleManager::class)->enable(SigningModule::KEY, $c->company, $c->user);

            CurrentCompany::run($c->company, function () use ($c): void {
                $nda = $this->document($c, 'Sekretessavtal med Kunden Exempel AB', 'Sekretessavtal', 'Parterna förbinder sig att inte lämna ut den andras affärshemligheter.');
                $lease = $this->document($c, 'Hyresavtal kontorslokal', 'Hyresavtal', 'Hyresgästen hyr kontorslokalen på Exempelgatan 1 i två år med tre månaders uppsägning.');
                $employment = $this->document($c, 'Anställningsavtal, Eva Exempelsson', 'Anställningsavtal', 'Anställningen gäller tillsvidare med sex månaders provanställning och tre månaders ömsesidig uppsägning.');

                // Uploaded and not sent yet: shown under "Uppladdade dokument som inte är skickade" in the wizard's first step.
                $draft = $this->document($c, 'Samarbetsavtal, utkast', 'Samarbetsavtal', 'Parterna samarbetar kring utveckling av en gemensam tjänst.');

                $template = $this->templates($c, $draft);
                $this->bulkSend($c, $template);

                $send = app(SendForSigning::class);

                $signedEnvelope = $send->envelope($c->user, $c->company, $nda, ['signers' => [['email' => 'karin.exempelsson@kunden-exempel.test', 'step' => 1, 'verification' => 'NONE']], 'copies' => [['email' => 'avtal@exempelbolaget.test', 'name' => 'Avtalsarkivet']]], minSigners: 1);
                $this->sign($signedEnvelope->id, 'Karin Exempelsson', 'VD');

                $leaseEnvelope = $send->envelope($c->user, $c->company, $lease, ['signers' => [
                    ['email' => 'anna.exempelsson@fastighet-exempel.test', 'step' => 1, 'verification' => 'EMAIL_CODE'],
                    ['email' => 'bo.exempelsson@fastighet-exempel.test', 'step' => 2, 'verification' => 'EMAIL_CODE'],
                ], 'auto_reminders' => true, 'reminder_days' => 3], minSigners: 1);

                $employmentEnvelope = $send->envelope($c->user, $c->company, $employment, ['signers' => [['email' => 'eva.exempelsson@example.test', 'step' => 1, 'verification' => 'EMAIL_CODE']], 'confidential' => true], minSigners: 1);

                $this->finishedBulkSend($c, $template, [$signedEnvelope, $leaseEnvelope, $employmentEnvelope]);
                $this->sealCertificate($c);
            });
        });
    }

    /**
     * The company's own seal certificate (ADR-046 S10), for the manual's image of the seal settings: made here
     * with a random password that is thrown away, never committed, and self-signed like a test certificate.
     */
    private function sealCertificate(DemoContext $c): void
    {
        $password = bin2hex(random_bytes(16));
        $seal = SelfSignedSeal::make((string) ($c->company->legal_name ?: $c->company->name).' – försegling', (string) ($c->company->legal_name ?: $c->company->name), 730, $password);

        app(ManageSealCertificates::class)->upload($c->user, $c->company, $seal['pkcs12'], $password);
    }

    /** Two templates (ADR-046 S14): one for the draft's kind of document, with two roles, and one for quotes. Roles and settings only, so the demo needs no PDF engine. */
    private function templates(DemoContext $c, SigningDocument $draft): SigningTemplate
    {
        $templates = app(ManageSigningTemplates::class);

        $document = $templates->create($c->user, $c->company, [
            'name' => 'Samarbetsavtal med två parter',
            'document_type' => SigningTemplate::DOCUMENT,
            'roles' => [
                ['label' => 'Kund', 'step' => 1, 'verification' => 'EMAIL_CODE'],
                ['label' => 'Säljare', 'step' => 2, 'verification' => 'NONE', 'internal' => true],
            ],
            'deadline_days' => 14,
            'auto_reminders' => true,
            'reminder_days' => 3,
            'copies' => [['email' => 'avtal@exempelbolaget.test', 'name' => 'Avtalsarkivet', 'language' => 'sv']],
        ], $draft);

        $templates->create($c->user, $c->company, [
            'name' => 'Standardoffert med motpart',
            'document_type' => SigningTemplate::QUOTE,
            'roles' => [
                ['label' => 'Kund', 'step' => 1, 'verification' => 'NONE'],
                ['label' => 'Säljare', 'step' => 2, 'verification' => 'NONE', 'internal' => true],
            ],
            'deadline_days' => 30,
            'auto_reminders' => true,
            'reminder_days' => 7,
        ]);

        return $document;
    }

    /** A bulk send waiting for its confirmation (ADR-046 S15): four rows, one with a faulty address, as the manual's screenshots show it. Nothing is sent. */
    private function bulkSend(DemoContext $c, SigningTemplate $template): void
    {
        $file = tempnam(sys_get_temp_dir(), 'demo-bulk');
        file_put_contents($file, implode("\n", [
            'Kund e-post;Kund namn;Kund språk;Säljare e-post',
            'karin.exempelsson@kunden-exempel.test;Karin Exempelsson;sv;saljare@exempelbolaget.test',
            'olof.exempelsson@bygg-exempel.test;Olof Exempelsson;sv;saljare@exempelbolaget.test',
            'anna.exempelsson@fastighet-exempel;Anna Exempelsson;sv;saljare@exempelbolaget.test',
            'sam.exempelsson@studio-exempel.test;Sam Exempelsson;en;saljare@exempelbolaget.test',
        ])."\n");

        try {
            app(ManageSigningBulkSends::class)->prepare($c->user, $c->company, $template, $file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * A finished bulk send for the result list of the manual (ADR-046 S15). Written straight into the tables, with the
     * envelopes the demo already made standing in for the ones a run would have made: the demo sends no mail.
     *
     * @param  list<SigningEnvelope>  $envelopes
     */
    private function finishedBulkSend(DemoContext $c, SigningTemplate $template, array $envelopes): void
    {
        $run = SigningBulkSend::create([
            'company_id' => $c->company->id,
            'template_id' => $template->id,
            'status' => SigningBulkSend::COMPLETED,
            'total_rows' => 4,
            'valid_rows' => 4,
            'invalid_rows' => 0,
            'sent_count' => 3,
            'failed_count' => 1,
            'created_by' => $c->user->id,
            'confirmed_at' => now()->subDays(2),
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2)->addMinutes(3),
        ]);

        foreach ($envelopes as $index => $envelope) {
            SigningBulkSendRow::create(['company_id' => $c->company->id, 'bulk_send_id' => $run->id, 'row_number' => $index + 2, 'status' => SigningBulkSendRow::SENT, 'envelope_id' => $envelope->id, 'processed_at' => now()->subDays(2)]);
        }

        SigningBulkSendRow::create(['company_id' => $c->company->id, 'bulk_send_id' => $run->id, 'row_number' => 5, 'status' => SigningBulkSendRow::FAILED, 'reason' => 'E-postkvoten tog slut. Läs in raden i ett nytt massutskick.', 'processed_at' => now()->subDays(2)]);
    }

    private function document(DemoContext $c, string $title, string $heading, string $text): SigningDocument
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml('<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 12px"><h1>'.e($heading).'</h1><p>'.e($text).'</p><p>'.e($c->company->name).'</p></body></html>');
        $pdf->render();
        $bytes = (string) $pdf->output();

        $path = "signing-documents/{$c->company->id}/".Str::uuid()->toString().'.pdf';
        Storage::disk(SigningRequest::DISK)->put($path, $bytes);

        return SigningDocument::create([
            'company_id' => $c->company->id,
            'title' => $title,
            'path' => $path,
            'name' => Str::slug($heading).'.pdf',
            'sha256' => hash('sha256', $bytes),
            'bytes' => strlen($bytes),
            'page_count' => 1,
            'uploaded_by' => $c->user->id,
        ]);
    }

    private function sign(string $envelopeId, string $name, string $title): void
    {
        $request = SigningRequest::query()->where('envelope_id', $envelopeId)->firstOrFail();
        $links = app(SignAtLink::class);

        // The signer accepts the consent to electronic records first, as on the page (ADR-046 S4).
        app(RecordSigningConsent::class)->give($request, SigningConsent::accepted());

        // A one-signer envelope keeps its link in signing_link_tokens; find the signer through the request itself.
        $links->sign($request, [
            'name' => $name,
            'title' => $title,
            'accept' => '1',
            'method' => SigningRequest::TYPED,
            'signature' => null,
            'typed_signature' => $name,
            'document_sha256' => $request->document_sha256,
            ...SigningPdfs::acceptanceFields($request),
        ], '192.0.2.10', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Exempel');
    }
}
