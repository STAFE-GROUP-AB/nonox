<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry\Import;

use Carbon\CarbonImmutable;

/**
 * Bolagsverket's part of the high-value datasets as a weekly bulk file
 * (ADR-047): `;`-separated with quoted fields (a business description can
 * span lines), UTF-8, columns organisationsidentitet, namnskyddslopnummer,
 * registreringsland, organisationsnamn, organisationsform,
 * avregistreringsdatum, avregistreringsorsak,
 * pagandeAvvecklingsEllerOmstruktureringsforfarande, registreringsdatum,
 * verksamhetsbeskrivning and postadress. Parts are packed with `$`, lists
 * with `|`. Free, no key and no agreement.
 *
 * It only completes companies the SCB file has added: the registration
 * date, deregistration and ongoing bankruptcy, liquidation or
 * reorganisation. Rows with a personal number are ignored. The business
 * description and the names are not read (SCB's name is kept; data
 * minimisation).
 */
final class BolagsverketBulkFile implements RegistrySource
{
    public const string KEY = 'bolagsverket-bulk';

    private int $read = 0;

    public function __construct(private readonly BulkFile $file) {}

    public static function make(?string $localFile): self
    {
        return new self(new BulkFile((string) config('services.company_register.bolagsverket_bulk_url'), $localFile, 'bolagsverket_bulkfil.zip'));
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): ?string
    {
        return $this->file->version();
    }

    public function createsRows(): bool
    {
        return false;
    }

    public function updateColumns(): array
    {
        return ['registered_on', 'deregistered_on', 'deregistration_reason', 'proceedings', 'proceedings_since', 'bolagsverket_fetched_at', 'sources'];
    }

    public function position(): string
    {
        return (string) $this->read;
    }

    public function rows(?string $resumeFrom): iterable
    {
        $skip = (int) $resumeFrom;
        $handle = $this->file->open();
        $fetchedAt = CarbonImmutable::now();

        try {
            $header = fgetcsv($handle, null, ';', '"', '');

            if (! is_array($header)) {
                return;
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $columns = array_flip(array_map(fn (mixed $name): string => trim((string) $name), $header));

            while (($values = fgetcsv($handle, null, ';', '"', '')) !== false) {
                $this->read++;

                if ($this->read <= $skip || $values === [null]) {
                    continue;
                }

                $value = fn (string $name): string => trim((string) ($values[$columns[$name] ?? -1] ?? ''));

                yield $this->row($value, $fetchedAt);
            }
        } finally {
            $this->file->close($handle);
        }
    }

    /** @param  callable(string): string  $value */
    private function row(callable $value, CarbonImmutable $fetchedAt): ?RegistryRow
    {
        $identity = explode('$', $value('organisationsidentitet'))[0];

        // A personal number (a sole trader) has 0 or 1 as its third digit; it is never read.
        if (! preg_match('/^\d{10}$/', $identity) || (int) $identity[2] < 2) {
            return null;
        }

        [$proceedings, $since] = $this->proceedings($value('pagandeAvvecklingsEllerOmstruktureringsforfarande'));
        $reason = $value('avregistreringsorsak');

        return new RegistryRow($identity, [
            'registered_on' => self::date($value('registreringsdatum')),
            'deregistered_on' => self::date($value('avregistreringsdatum')),
            'deregistration_reason' => $reason === '' ? null : substr((string) preg_replace('/-AVORG$/', '', $reason), 0, 40),
            'proceedings' => $proceedings,
            'proceedings_since' => $since,
            'bolagsverket_fetched_at' => $fetchedAt,
        ]);
    }

    /**
     * The first ongoing proceeding, e.g. "|KK-AVOMFO$2024-06-05" → [KK, 2024-06-05].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function proceedings(string $value): array
    {
        foreach (explode('|', $value) as $entry) {
            if (trim($entry) === '') {
                continue;
            }

            [$code, $date] = array_pad(explode('$', $entry, 2), 2, '');
            $code = trim((string) preg_replace('/-AVOMFO$/', '', $code));

            if ($code !== '') {
                return [substr($code, 0, 20), self::date($date)];
            }
        }

        return [null, null];
    }

    private static function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) ? trim($value) : null;
    }
}
