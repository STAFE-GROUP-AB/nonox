<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry\Import;

use Carbon\CarbonImmutable;

/**
 * SCB's part of the high-value datasets as a weekly bulk file (ADR-047):
 * tab-separated, ISO-8859-1, a header row with ForAndrTyp, COAdress,
 * Foretagsnamn, FtgStat, Gatuadress, JEStat, JurForm, Namn, Ng1–Ng5,
 * PeOrgNr, PostNr, PostOrt, RegDatKtid and Reklamsparrtyp (and an m-twin
 * per column). Free, no key and no agreement.
 *
 * Only legal persons are read: PeOrgNr must be 16 followed by an
 * organisation number, and the legal forms of natural persons (10), simple
 * partnerships (21), shipping partnerships (22) and estates of deceased
 * persons (91) are skipped (ADR-047, GDPR).
 */
final class ScbBulkFile implements RegistrySource
{
    public const string KEY = 'scb-bulk';

    /** SCB legal forms that are, or are named after, natural persons. */
    public const array EXCLUDED_FORMS = ['10', '21', '22', '91'];

    private int $read = 0;

    public function __construct(private readonly BulkFile $file) {}

    public static function make(?string $localFile): self
    {
        return new self(new BulkFile((string) config('services.company_register.scb_bulk_url'), $localFile, 'scb_bulkfil.zip'));
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
        return true;
    }

    public function updateColumns(): array
    {
        // registered_on is SCB's own registration date; Bolagsverket's (the real one) wins once it has been read.
        return ['name', 'legal_form', 'scb_status', 'sni_code', 'sni_division', 'sni_codes', 'address_line1', 'co_address', 'postal_code', 'city', 'advertising_block', 'fetched_at', 'last_import_id', 'gone_at', 'sources'];
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
            $header = fgets($handle);

            if ($header === false) {
                return;
            }

            $columns = array_flip(explode("\t", rtrim(self::utf8($header), "\r\n")));

            while (($line = fgets($handle)) !== false) {
                $this->read++;

                if ($this->read <= $skip) {
                    continue;
                }

                $values = explode("\t", rtrim(self::utf8($line), "\r\n"));
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
        $identity = $value('PeOrgNr');
        $form = $value('JurForm');

        if (! preg_match('/^16(\d{10})$/', $identity, $match) || (int) $match[1][2] < 2 || in_array($form, self::EXCLUDED_FORMS, true)) {
            return null;
        }

        $name = $value('Namn');

        if ($name === '') {
            return null;
        }

        $sni = array_values(array_filter(
            array_map(fn (int $rank): string => $value("Ng{$rank}"), [1, 2, 3, 4, 5]),
            fn (string $code): bool => preg_match('/^\d{5}$/', $code) === 1,
        ));
        $status = $value('FtgStat');
        $registered = $value('RegDatKtid');

        return new RegistryRow($match[1], [
            'name' => mb_substr($name, 0, 255),
            'legal_form' => $form === '' ? null : substr($form, 0, 2),
            'scb_status' => ctype_digit($status) ? (int) $status : null,
            'sni_code' => $sni[0] ?? null,
            'sni_division' => isset($sni[0]) ? substr($sni[0], 0, 2) : null,
            'sni_codes' => $sni,
            'address_line1' => self::nullable($value('Gatuadress')),
            'co_address' => self::nullable($value('COAdress')),
            'postal_code' => self::nullable(preg_replace('/\D/', '', $value('PostNr'))),
            'city' => self::nullable($value('PostOrt')),
            'registered_on' => preg_match('/^(\d{4})(\d{2})(\d{2})$/', $registered, $date) ? "{$date[1]}-{$date[2]}-{$date[3]}" : null,
            // 2 in the bulk file (4 in the API) is an opt-out of postal advertising. TODO(legal-verify): the bulk file's code list.
            'advertising_block' => in_array($value('Reklamsparrtyp'), ['2', '4'], true),
            'fetched_at' => $fetchedAt,
        ]);
    }

    private static function utf8(string $line): string
    {
        return mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1');
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
