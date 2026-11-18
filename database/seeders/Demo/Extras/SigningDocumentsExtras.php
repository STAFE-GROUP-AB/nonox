<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\SigningPdfs;
use App\Models\SigningRequest;
use App\Modules\ModuleManager;
use App\Modules\Signing\Models\SigningDocument;
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
 * employment contract that is confidential. The PDFs are made here with dompdf
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
                $this->document($c, 'Samarbetsavtal, utkast', 'Samarbetsavtal', 'Parterna samarbetar kring utveckling av en gemensam tjänst.');

                $send = app(SendForSigning::class);

                $signedEnvelope = $send->envelope($c->user, $c->company, $nda, ['signers' => [['email' => 'karin.exempelsson@kunden-exempel.test', 'step' => 1, 'verification' => 'NONE']], 'copies' => [['email' => 'avtal@exempelbolaget.test', 'name' => 'Avtalsarkivet']]], minSigners: 1);
                $this->sign($signedEnvelope->id, 'Karin Exempelsson', 'VD');

                $send->envelope($c->user, $c->company, $lease, ['signers' => [
                    ['email' => 'anna.exempelsson@fastighet-exempel.test', 'step' => 1, 'verification' => 'EMAIL_CODE'],
                    ['email' => 'bo.exempelsson@fastighet-exempel.test', 'step' => 2, 'verification' => 'EMAIL_CODE'],
                ], 'auto_reminders' => true, 'reminder_days' => 3], minSigners: 1);

                $send->envelope($c->user, $c->company, $employment, ['signers' => [['email' => 'eva.exempelsson@example.test', 'step' => 1, 'verification' => 'EMAIL_CODE']], 'confidential' => true], minSigners: 1);
            });
        });
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
