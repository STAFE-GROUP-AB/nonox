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
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the public Verify page may say about a SHA-256 (ADR-046 amendment,
 * gap 6, S12, as decided 2026-11-22): whether NONOX keeps a file with exactly
 * that hash, and then only the facts below. Never a signer's name, e-mail
 * address or IP address (owner decision 11), never the document's title,
 * never an internal id.
 *
 * Only an envelope completed on or after NOTICE_FROM, of a company that has
 * switched the page on, is answered; anything else is "not found", exactly alike. Details are given
 * only when the hash is in exactly one envelope; in more than one (the same
 * PDF sent twice, by one company or two) the answer says so and nothing else.
 *
 * The lookup is across companies by design (the visitor has no account and
 * the hash is the key), inside CompanyScope::bypass for exactly the versions
 * and envelopes it names; everything else is read for the one company found.
 * A miss costs one query, a hit six, whatever the number of rows.
 */
final class VerifyLookup
{
    /**
     * The day the signing page started telling signers about the Verify page. Only envelopes completed at or
     * after this moment are answered, so no signer is covered who was not told (legal re-check, N1).
     */
    public const string NOTICE_FROM = '2026-11-23 00:00:00 Europe/Stockholm';

    public const string ONE = 'one';

    public const string MANY = 'many';

    public const string NONE = 'none';

    /**
     * @return array{status: string, found: bool, heading: string, lines: list<array{string, string}>}
     */
    public function find(string $sha256): array
    {
        // Across companies on purpose (ADR-046 amendment, gap 6): the hash is the only key a visitor has.
        // Completed envelopes of companies with the page on, decided in the query, before anything is counted.
        $envelopeIds = CompanyScope::bypass(fn (): array => SigningDocumentVersion::query()
            ->where('sha256', $sha256)
            ->whereIn('envelope_id', fn ($query) => $query->select('signing_envelopes.id')->from('signing_envelopes')
                ->join('teams', 'teams.id', '=', 'signing_envelopes.company_id')
                ->where('signing_envelopes.status', EnvelopeStatus::Completed->value)
                ->where('signing_envelopes.completed_at', '>=', CarbonImmutable::parse(self::NOTICE_FROM))
                ->where('teams.signing_verify_enabled', true))
            ->distinct()->limit(2)->pluck('envelope_id')->all());

        if ($envelopeIds === []) {
            return $this->notFound();
        }

        if (count($envelopeIds) > 1) {
            return $this->many();
        }

        [$version, $envelope] = CompanyScope::bypass(function () use ($sha256, $envelopeIds): array {
            $version = SigningDocumentVersion::query()->where('sha256', $sha256)->where('envelope_id', $envelopeIds[0])->orderBy('id')->first(['id', 'company_id', 'envelope_id', 'kind', 'signing_request_id', 'sha256', 'created_at']);

            return [$version, SigningEnvelope::query()->find($envelopeIds[0])];
        });

        if (! $version instanceof SigningDocumentVersion || ! $envelope instanceof SigningEnvelope) {
            return $this->notFound();
        }

        $company = Company::query()->find($envelope->company_id, ['id', 'name', 'legal_name']);
        $signers = CompanyScope::bypass(fn (): Collection => SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('step')->orderBy('id')->get(['id', 'step', 'status', 'verification']));
        $check = AuditLog::query()->where('company_id', $envelope->company_id)->where('action', VerifySigningChainsCommand::ACTION)->latest('id')->first(['payload', 'created_at']);

        return $this->describe($version, $envelope, $company, $signers, $check);
    }

    /**
     * The answer for a file NONOX does not keep, or whose company has not switched the page on. The same shape as a hit.
     *
     * @return array{status: string, found: bool, heading: string, lines: list<array{string, string}>}
     */
    public function notFound(): array
    {
        return ['status' => self::NONE, 'found' => false, 'heading' => __('Filen finns inte hos NONOX'), 'lines' => [[
            __('Vad det betyder'),
            __('NONOX har ingen signering med en fil som är exakt den här. Filen kan vara ändrad, vara en annan fil än den som signerades, eller höra till en signering som inte längre sparas.'),
        ]]];
    }

    /**
     * @return array{status: string, found: bool, heading: string, lines: list<array{string, string}>}
     */
    private function many(): array
    {
        return ['status' => self::MANY, 'found' => true, 'heading' => __('NONOX har en fil med den här kontrollsumman'), 'lines' => [[
            __('Vad det betyder'),
            __('Den här filen har skickats för signering mer än en gång, så sidan kan inte säga vilken signering den hör till. Kontrollera i stället den signerade filen eller den förseglade filen som du fick efter signeringen.'),
        ]]];
    }

    /**
     * @param  Collection<int, SigningRequest>  $signers
     * @return array{status: string, found: bool, heading: string, lines: list<array{string, string}>}
     */
    private function describe(SigningDocumentVersion $version, SigningEnvelope $envelope, ?Company $company, Collection $signers, ?AuditLog $check): array
    {
        $zone = config('app.timezone');
        $time = fn (mixed $value): string => $value === null ? '–' : $value->setTimezone($zone)->format('Y-m-d H:i');
        $lines = [];

        $lines[] = [__('Företag'), $company instanceof Company ? ($company->legal_name ?: $company->name) : '–'];
        $lines[] = [__('Filen är'), $this->versionLabel($version, $signers)];
        $lines[] = [__('Läge'), __('Signerat av alla, :time', ['time' => $time($envelope->completed_at)])];

        $signed = $signers->filter(fn (SigningRequest $signer): bool => $signer->status === SigningStatus::Signed);
        $lines[] = [__('Undertecknare'), __(':signed av :total har signerat', ['signed' => $signed->count(), 'total' => $signers->count()])];

        $methods = $signed->countBy(fn (SigningRequest $signer): string => $signer->verification->evidence())->map(fn (int $count, string $label): string => "{$label} ({$count})")->values();

        if ($methods->isNotEmpty()) {
            $lines[] = [__('Kontroll av undertecknarna'), $methods->implode(', ')];
        }

        $lines[] = [__('Förseglad av NONOX'), $this->sealLine($version, $envelope)];
        $lines[] = [__('Händelsekedjan'), $this->chainLine($envelope, $version, $check, $time)];

        return ['status' => self::ONE, 'found' => true, 'heading' => __('NONOX har en fil med den här kontrollsumman'), 'lines' => $lines];
    }

    /** A seal level only for a file that is the sealed or the re-timestamped one; the page does not check the seal in the file. */
    private function sealLine(SigningDocumentVersion $version, SigningEnvelope $envelope): string
    {
        $sealed = in_array($version->kind, [SigningDocumentVersion::SEALED, SigningDocumentVersion::TIMESTAMPED], true)
            && $envelope->seal_status === SealStatus::Sealed && $envelope->seal_level !== null;

        return $sealed
            ? __(':level (sidan kontrollerar inte stämpeln i filen; öppna filen i en PDF-läsare för det)', ['level' => $envelope->seal_level->label()])
            : __('Inte förseglad');
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

    /**
     * What the latest nightly run (signing:verify) found, in words. Only a run that came after the file and the
     * completion can have covered this envelope; an earlier one, or none, is said as it is.
     */
    private function chainLine(SigningEnvelope $envelope, SigningDocumentVersion $version, ?AuditLog $check, callable $time): string
    {
        $covered = $check instanceof AuditLog
            && $envelope->completed_at !== null
            && $check->created_at >= max($envelope->completed_at, $version->created_at);

        if (! $covered) {
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
