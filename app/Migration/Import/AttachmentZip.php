<?php

declare(strict_types=1);

namespace App\Migration\Import;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\FiscalYear;
use App\Models\ImportedVoucher;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/**
 * Receipts and other underlag in a ZIP, linked to the vouchers a SIE import
 * created (ADR-076, section 4). No vendor documents a naming scheme, so the
 * safest link is a mapping file lank.csv in the ZIP (filnamn;serie;nummer;ar);
 * the folder layout YYYY/SERIES-NUMBER.ext is also read. Files that match
 * neither are reported, not guessed.
 *
 * The archive is checked before anything is read from it: entry names with
 * "..", absolute paths or drive letters are rejected, only pdf, jpg, jpeg,
 * png and webp are accepted, one file is at most 10 MB, the unpacked size
 * is capped and an entry that unpacks to more than 200 times its packed size
 * is refused. Files are read from the archive one at a time and are never
 * written to a path the archive chose.
 */
final class AttachmentZip
{
    public const int MAX_ZIP_KILOBYTES = 102_400;

    public const int MAX_ENTRIES = 5_000;

    public const int MAX_UNPACKED_BYTES = 500 * 1024 * 1024;

    public const int MAX_FILE_BYTES = AttachToVoucher::MAX_KILOBYTES * 1024;

    public const int MAX_RATIO = 200;

    public const array EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const string MAPPING_FILE = 'lank.csv';

    public const string LINKED = 'linked';

    public const string UNLINKED = 'unlinked';

    public const string DUPLICATE = 'duplicate';

    public const string REJECTED = 'rejected';

    /**
     * Classifies every entry. Nothing is written.
     *
     * @return Generator<int, array{
     *     index: int,
     *     name: string,
     *     status: string,
     *     reason: array<array-key, mixed>|string|null,
     *     voucher: ?string,
     *     target: ?string
     * }>
     *
     * @throws RuntimeException when the archive itself is not acceptable
     */
    public static function plan(string $path): Generator
    {
        $zip = self::open($path);

        try {
            $links = self::mappingFile($zip);
            $vouchers = self::voucherIndex();
            $hashesByVoucher = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false || str_ends_with((string) $stat['name'], '/')) {
                    continue;
                }

                $name = (string) $stat['name'];

                if (self::ignored($name) || $name === self::MAPPING_FILE) {
                    continue;
                }

                $rejection = self::rejection($name, (int) $stat['size'], (int) $stat['comp_size']);

                if ($rejection !== null) {
                    yield ['index' => $i, 'name' => self::shown($name), 'status' => self::REJECTED, 'reason' => $rejection, 'voucher' => null, 'target' => null];

                    continue;
                }

                $target = $links[mb_strtolower($name)] ?? $links[mb_strtolower(basename($name))] ?? self::fromFolder($name);

                if ($target === null) {
                    yield ['index' => $i, 'name' => self::shown($name), 'status' => self::UNLINKED, 'reason' => __('Ingen koppling i lank.csv och filen ligger inte i formatet ÅÅÅÅ/SERIE-NUMMER.'), 'voucher' => null, 'target' => null];

                    continue;
                }

                [$series, $number, $year] = $target;
                $voucherId = self::resolve($vouchers, $series, $number, $year);
                $label = "{$series} {$number}".($year === null ? '' : " ({$year})");

                if ($voucherId === null) {
                    yield ['index' => $i, 'name' => self::shown($name), 'status' => self::UNLINKED, 'reason' => __('Verifikationen :target finns inte bland de inlästa, eller finns i flera år.', ['target' => $label]), 'voucher' => null, 'target' => $label];

                    continue;
                }

                $hash = self::hash($zip, $i);

                if ($hash === null || isset($hashesByVoucher[$voucherId][$hash])) {
                    yield ['index' => $i, 'name' => self::shown($name), 'status' => $hash === null ? self::REJECTED : self::DUPLICATE, 'reason' => $hash === null ? __('Filen går inte att läsa ur arkivet.') : __('Samma fil finns redan på verifikationen.'), 'voucher' => $voucherId, 'target' => $label];

                    continue;
                }

                $hashesByVoucher[$voucherId][$hash] = true;
                $already = VoucherAttachment::query()->where('voucher_id', $voucherId)->where('sha256', $hash)->exists();

                yield [
                    'index' => $i,
                    'name' => self::shown($name),
                    'status' => $already ? self::DUPLICATE : self::LINKED,
                    'reason' => $already ? __('Samma fil finns redan på verifikationen.') : null,
                    'voucher' => $voucherId,
                    'target' => $label,
                ];
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Attaches one linked entry to its voucher with the ordinary action.
     *
     * @throws ValidationException when the file is not an acceptable underlag
     */
    public static function attach(string $path, int $index, string $voucherId, User $user, AttachToVoucher $attach): void
    {
        $zip = self::open($path);

        try {
            $stat = $zip->statIndex($index);
            $name = $stat === false ? 'underlag' : basename((string) $stat['name']);
            $contents = self::read($zip, $index);
        } finally {
            $zip->close();
        }

        $temporary = tempnam(sys_get_temp_dir(), 'nonox-underlag') ?: throw new RuntimeException('No temporary file.');

        try {
            file_put_contents($temporary, $contents);
            $attach($user, Voucher::query()->findOrFail($voucherId), new UploadedFile($temporary, $name, null, null, true));
        } finally {
            @unlink($temporary);
        }
    }

    /** @throws RuntimeException */
    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('ZIP-filen går inte att öppna.'));
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            throw new RuntimeException(__('ZIP-filen har fler än :max filer. Dela upp den.', ['max' => self::MAX_ENTRIES]));
        }

        $unpacked = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $unpacked += (int) ($zip->statIndex($i)['size'] ?? 0);
        }

        if ($unpacked > self::MAX_UNPACKED_BYTES) {
            $zip->close();

            throw new RuntimeException(__('ZIP-filen blir större än 500 MB när den packas upp. Dela upp den.'));
        }

        return $zip;
    }

    private static function rejection(string $name, int $size, int $packed): ?string
    {
        $segments = preg_split('#[\\\\/]#', $name) ?: [];

        if (str_contains($name, "\0") || str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('/^[A-Za-z]:/', $name) === 1 || in_array('..', $segments, true)) {
            return __('Filnamnet pekar utanför arkivet och avvisas.');
        }

        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return __('Filtypen tas inte emot. Bara PDF, JPG, PNG och WebP.');
        }

        if ($size > self::MAX_FILE_BYTES) {
            return __('Filen är större än 10 MB.');
        }

        if ($packed > 0 && intdiv($size, $packed) > self::MAX_RATIO) {
            return __('Filen packas upp till orimligt mycket mer än den väger packad och avvisas.');
        }

        return null;
    }

    private static function ignored(string $name): bool
    {
        return str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store' || basename($name) === 'Thumbs.db';
    }

    private static function shown(string $name): string
    {
        return mb_substr((string) preg_replace('/[^\PC]/u', '', $name), 0, 120);
    }

    /** @return array<string, array{0: string, 1: string, 2: ?int}> lower case file name => series, number, year */
    private static function mappingFile(ZipArchive $zip): array
    {
        $contents = $zip->getFromName(self::MAPPING_FILE);

        if ($contents === false || strlen($contents) > 5_000_000) {
            return [];
        }

        $contents = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        $lines = preg_split('/\R/', $contents) ?: [];
        $delimiter = substr_count((string) ($lines[0] ?? ''), ';') >= substr_count((string) ($lines[0] ?? ''), ',') ? ';' : ',';
        $links = [];

        foreach (array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');

            if (count($cells) < 3 || trim((string) $cells[0]) === '' || ! ctype_digit(trim((string) $cells[2]))) {
                continue;
            }

            $year = isset($cells[3]) && ctype_digit(trim((string) $cells[3])) ? (int) trim((string) $cells[3]) : null;
            $links[mb_strtolower(trim((string) $cells[0]))] = [strtoupper(trim((string) $cells[1])), ltrim(trim((string) $cells[2]), '0') ?: '0', $year];
        }

        return $links;
    }

    /** @return ?array{0: string, 1: string, 2: ?int} */
    private static function fromFolder(string $name): ?array
    {
        if (preg_match('#^(\d{4})/([A-Za-z]+)-(\d+)(?:[-_ ].*)?\.[A-Za-z]+$#', $name, $match) !== 1) {
            return null;
        }

        return [strtoupper($match[2]), ltrim($match[3], '0') ?: '0', (int) $match[1]];
    }

    /**
     * The vouchers a SIE import made, once: "series|number" => list of [voucher id, year].
     *
     * @return array<string, list<array{0: string, 1: int}>>
     */
    private static function voucherIndex(): array
    {
        $years = FiscalYear::query()->get(['id', 'start_date'])->mapWithKeys(fn (FiscalYear $year): array => [$year->id => (int) $year->start_date->format('Y')]);
        $index = [];

        foreach (ImportedVoucher::query()->get(['voucher_id', 'fiscal_year_id', 'original_series', 'original_number']) as $imported) {
            $index[strtoupper($imported->original_series).'|'.(ltrim($imported->original_number, '0') ?: '0')][] = [$imported->voucher_id, $years[$imported->fiscal_year_id] ?? 0];
        }

        return $index;
    }

    /** @param  array<string, list<array{0: string, 1: int}>>  $index */
    private static function resolve(array $index, string $series, string $number, ?int $year): ?string
    {
        $candidates = $index["{$series}|{$number}"] ?? [];

        if ($year !== null) {
            $candidates = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate[1] === $year));
        }

        return count($candidates) === 1 ? $candidates[0][0] : null;
    }

    private static function hash(ZipArchive $zip, int $index): ?string
    {
        try {
            return hash('sha256', self::read($zip, $index));
        } catch (RuntimeException) {
            return null;
        }
    }

    private static function read(ZipArchive $zip, int $index): string
    {
        $stat = $zip->statIndex($index);
        $stream = $zip->getStream((string) ($stat['name'] ?? ''));

        if ($stat === false || $stream === false) {
            throw new RuntimeException('Cannot read the entry.');
        }

        // Never more than the declared size plus one byte, so a lying entry cannot fill the memory.
        $contents = (string) stream_get_contents($stream, min((int) $stat['size'], self::MAX_FILE_BYTES) + 1);
        fclose($stream);

        if (strlen($contents) > (int) $stat['size']) {
            throw new RuntimeException('The entry is larger than it says.');
        }

        return $contents;
    }
}
