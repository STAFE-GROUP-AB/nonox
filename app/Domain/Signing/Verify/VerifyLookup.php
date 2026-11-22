<?php

declare(strict_types=1);

namespace App\Domain\Signing\Verify;

use App\Console\Commands\VerifySigningChainsCommand;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Seal\SealStatus;
use App\Domain\Signing\SigningStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Collection;

/**
 * What the public Verify page may say about a SHA-256 (ADR-046 amendment,
 * gap 6, S12): whether NONOX keeps a file with exactly that hash, and then
 * only the facts the amendment lists. Never a signer's name, e-mail address
 * or IP address (owner decision 11), never an internal id, never anything
 * for an envelope that is still out, a draft or has expired.
 *
 * The lookup is across companies by design (the visitor has no account and
 * the hash is the key), inside CompanyScope::bypass for exactly the versions
 * and envelopes it names; everything else is read for the one company found.
 * A miss costs one query, a hit five, whatever the number of rows.
 */
final class VerifyLookup
{
    /** How many versions with the same hash are read; the newest answerable envelope is the one shown. */
    private const int CANDIDATES = 8;

    /**
     * @return ?array{heading: string, lines: list<array{string, string}>} null when no file with that hash is kept
     */
    public function find(string $sha256): ?array
    {
        // Across companies on purpose (ADR-046 amendment, gap 6): the hash is the only key a visitor has.
        [$versions, $envelopes] = CompanyScope::bypass(function () use ($sha256): array {
            // Indexed on sha256 (signing_document_versions_sha256_index).
            $versions = SigningDocumentVersion::query()->where('sha256', $sha256)->orderByDesc('id')->limit(self::CANDIDATES)
                ->get(['id', 'company_id', 'envelope_id', 'kind', 'signing_request_id', 'sha256']);

            if ($versions->isEmpty()) {
                return [$versions, collect()];
            }

            return [$versions, SigningEnvelope::query()->whereIn('id', $versions->pluck('envelope_id')->unique()->all())->get()->keyBy('id')];
        });

        $answerable = $versions->filter(fn (SigningDocumentVersion $version): bool => $this->answers($envelopes->get($version->envelope_id)))->values();
        $version = $answerable->first();

        if (! $version instanceof SigningDocumentVersion) {
            return null;
        }

        /** @var SigningEnvelope $envelope */
        $envelope = $envelopes->get($version->envelope_id);
        $company = Company::query()->find($envelope->company_id, ['id', 'name', 'legal_name']);
        $signers = CompanyScope::bypass(fn (): Collection => SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('step')->orderBy('id')->get(['id', 'step', 'status', 'verification']));
        $check = AuditLog::query()->where('company_id', $envelope->company_id)->where('action', VerifySigningChainsCommand::ACTION)->latest('id')->first(['payload', 'created_at']);

        return $this->describe($version, $envelope, $company, $signers, $check, $answerable->count());
    }

    /**
     * The answer for a file NONOX does not keep. The same shape as a hit.
     *
     * @return array{heading: string, lines: list<array{string, string}>}
     */
    public function notFound(): array
    {
        return ['heading' => __('Filen finns inte hos NONOX'), 'lines' => [[
            __('Vad det betyder'),
            __('NONOX har ingen signering med en fil som är exakt den här. Filen kan vara ändrad, vara en annan fil än den som signerades, eller höra till en signering som inte längre sparas.'),
        ]]];
    }

    /** Completed, declined and voided envelopes are answered, also for archived companies (evidence stays checkable); nothing else. */
    private function answers(?SigningEnvelope $envelope): bool
    {
        return $envelope instanceof SigningEnvelope
            && in_array($envelope->status, [EnvelopeStatus::Completed, EnvelopeStatus::Declined, EnvelopeStatus::Voided], true);
    }

    /**
     * @param  Collection<int, SigningRequest>  $signers
     * @return array{heading: string, lines: list<array{string, string}>}
     */
    private function describe(SigningDocumentVersion $version, SigningEnvelope $envelope, ?Company $company, Collection $signers, ?AuditLog $check, int $matches): array
    {
        $zone = config('app.timezone');
        $time = fn (mixed $value): string => $value === null ? '–' : $value->setTimezone($zone)->format('Y-m-d H:i');
        $lines = [];

        $lines[] = [__('Företag'), $company instanceof Company ? ($company->legal_name ?: $company->name) : '–'];
        // A confidential envelope's title is seen only by its sender and by those who manage signing (S7).
        $lines[] = [__('Dokument'), $envelope->confidential ? __('Konfidentiellt dokument') : (string) ($envelope->title ?: '–')];
        $lines[] = [__('Filen är'), $this->versionLabel($version, $signers)];
        $lines[] = [__('Läge'), $this->stateLine($envelope, $time)];

        $signed = $signers->filter(fn (SigningRequest $signer): bool => $signer->status === SigningStatus::Signed);
        $lines[] = [__('Undertecknare'), __(':signed av :total har signerat', ['signed' => $signed->count(), 'total' => $signers->count()])];

        $methods = $signed->countBy(fn (SigningRequest $signer): string => $signer->verification->evidence())->map(fn (int $count, string $label): string => "{$label} ({$count})")->values();

        if ($methods->isNotEmpty()) {
            $lines[] = [__('Kontroll av undertecknarna'), $methods->implode(', ')];
        }

        $lines[] = [__('Försegling'), $envelope->seal_status === SealStatus::Sealed && $envelope->seal_level !== null ? $envelope->seal_level->label() : __('Ingen försegling')];
        $lines[] = [__('Händelsekedjan'), $this->chainLine($envelope, $check, $time)];

        if ($matches > 1) {
            $lines[] = [__('Observera'), __('Samma fil finns i flera signeringar. Den senaste visas.')];
        }

        $lines[] = [__('Vad det betyder'), __('Filen är oförändrad: den är exakt den fil som NONOX sparade. Sidan kontrollerar inte vem som har skrivit under eller om en underskrift är giltig.')];

        return ['heading' => __('Filen är oförändrad'), 'lines' => $lines];
    }

    /** @param  Collection<int, SigningRequest>  $signers */
    private function versionLabel(SigningDocumentVersion $version, Collection $signers): string
    {
        return match ($version->kind) {
            SigningDocumentVersion::ORIGINAL => __('Originalet som skickades för signering'),
            SigningDocumentVersion::AFTER_SIGNER => $this->afterSigner($version, $signers),
            SigningDocumentVersion::SEALED => __('Den förseglade filen'),
            SigningDocumentVersion::TIMESTAMPED => __('Den omstämplade filen'),
            default => '–',
        };
    }

    /** @param  Collection<int, SigningRequest>  $signers */
    private function afterSigner(SigningDocumentVersion $version, Collection $signers): string
    {
        $signer = $signers->firstWhere('id', $version->signing_request_id);

        return $signer instanceof SigningRequest
            ? __('Versionen efter att undertecknare i steg :step har signerat', ['step' => $signer->step])
            : __('En version efter en underskrift');
    }

    private function stateLine(SigningEnvelope $envelope, callable $time): string
    {
        return match ($envelope->status) {
            EnvelopeStatus::Completed => __('Signerat av alla, :time', ['time' => $time($envelope->completed_at)]),
            EnvelopeStatus::Declined => __('Avböjt, :time', ['time' => $time($envelope->declined_at)]),
            default => __('Tillbakadraget, :time', ['time' => $time($envelope->voided_at)]),
        };
    }

    /** What the latest nightly run (signing:verify) found, in words; no run yet is said as it is. */
    private function chainLine(SigningEnvelope $envelope, ?AuditLog $check, callable $time): string
    {
        if (! $check instanceof AuditLog) {
            return __('Har inte kontrollerats än');
        }

        foreach ((array) ($check->payload['problems'] ?? []) as $problem) {
            if (($problem['envelope_id'] ?? null) === $envelope->id) {
                return __('Avvikelse vid kontrollen :time', ['time' => $time($check->created_at)]);
            }
        }

        return __('Oförändrad vid kontrollen :time', ['time' => $time($check->created_at)]);
    }
}
