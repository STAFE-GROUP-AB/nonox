<?php

declare(strict_types=1);

namespace App\Modules\Signing\Support;

use App\Domain\Signing\Verification\SignerCheck;
use App\Domain\Signing\Verification\SignerChecks;
use App\Migration\Import\RegisterFileReader;
use App\Migration\Import\RegisterTable;
use App\Models\SigningEnvelope;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Modules\Signing\Models\SigningBulkSendRow;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\Models\SigningTemplateRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reads the file of a bulk send (ADR-046 S15) and checks every row against
 * the template's roles, before anything is sent. A row has, for each role of
 * the template, the columns "<Roll> e-post" (required), "<Roll> namn",
 * "<Roll> språk" (sv or en) and, when the role's check is an access code,
 * "<Roll> åtkomstkod". CSV, TSV, text and XLSX files are read the way the
 * register importer reads them (RegisterFileReader).
 *
 * A row is PENDING (it will be sent once a person confirms) or INVALID with
 * a short reason that never holds an address. The same people twice are a
 * duplicate: the later row is INVALID. More than 500 rows refuse the whole
 * file.
 */
final class BulkSendRows
{
    /** Header spellings of the e-mail column, after RegisterFileReader::normaliseHeader. */
    private const array EMAIL_HEADERS = ['epost', 'email', 'mail'];

    /**
     * The column names a file for the template should have, for the example file and the page.
     *
     * @return list<string>
     */
    public static function headersFor(SigningTemplate $template): array
    {
        $headers = [];

        foreach ($template->roles as $role) {
            $headers[] = "{$role->label} e-post";
            $headers[] = "{$role->label} namn";
            $headers[] = "{$role->label} språk";

            if ($role->verification === SignerCheck::AccessCode->value) {
                $headers[] = "{$role->label} åtkomstkod";
            }
        }

        return $headers;
    }

    /**
     * @return array{
     *     rows: list<array{row: int, status: string, reason: ?string, payload: ?array{signers: list<array{role_key: string, email: string, name: string, language: string, access_code: string}>}}>,
     *     ignored: list<string>
     * }
     *
     * @throws ValidationException with the field `file` when the file cannot be used at all
     */
    public static function read(SigningTemplate $template, string $path): array
    {
        try {
            $table = RegisterFileReader::open($path);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        /** @var Collection<int, SigningTemplateRole> $roles */
        $roles = $template->roles;
        $columns = self::columns($table, $roles);
        $used = collect($columns)->flatMap(fn (array $role): array => array_values($role))->filter(fn (mixed $index): bool => $index !== null)->all();
        $ignored = [];

        foreach ($table->headers as $index => $header) {
            if (! in_array($index, $used, true) && trim($header) !== '') {
                $ignored[] = $header;
            }
        }

        $rows = [];
        $seen = [];

        foreach ($table->rows() as $line => $cells) {
            if (count($rows) >= SigningBulkSend::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => __('Filen har fler än :max rader. Dela upp den i flera körningar.', ['max' => SigningBulkSend::MAX_ROWS])]);
            }

            $rows[] = self::row((int) $line, $cells, $roles, $columns, $template->language, $seen);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('Filen har inga rader med uppgifter under rubrikraden.')]);
        }

        return ['rows' => $rows, 'ignored' => $ignored];
    }

    /**
     * Which column of the file holds what for each role.
     *
     * @param  Collection<int, SigningTemplateRole>  $roles
     * @return array<string, array{email: ?int, name: ?int, language: ?int, access_code: ?int}>
     *
     * @throws ValidationException
     */
    private static function columns(RegisterTable $table, Collection $roles): array
    {
        $normalised = array_map(RegisterFileReader::normaliseHeader(...), $table->headers);
        $columns = [];
        $missing = [];

        foreach ($roles as $role) {
            $base = RegisterFileReader::normaliseHeader($role->label);
            $find = function (array $names) use ($normalised, $base): ?int {
                foreach ($names as $name) {
                    $index = array_search($base.$name, $normalised, true);

                    if ($index !== false) {
                        return (int) $index;
                    }
                }

                return null;
            };

            $columns[$role->key] = [
                'email' => $find(self::EMAIL_HEADERS),
                'name' => $find(['namn', 'name']),
                'language' => $find(['sprak', 'språk', 'language']),
                'access_code' => $find(['atkomstkod', 'åtkomstkod', 'accesscode']),
            ];

            if ($columns[$role->key]['email'] === null) {
                $missing[] = "{$role->label} e-post";
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => __('Filen saknar kolumnerna :columns. Rubrikraden ska ha en kolumn med e-post för varje roll i mallen.', ['columns' => implode(', ', $missing)])]);
        }

        return $columns;
    }

    /**
     * @param  list<string>  $cells
     * @param  Collection<int, SigningTemplateRole>  $roles
     * @param  array<string, array{email: ?int, name: ?int, language: ?int, access_code: ?int}>  $columns
     * @param  array<string, int>  $seen  the people of the earlier rows, by key, set here
     * @return array{row: int, status: string, reason: ?string, payload: ?array{signers: list<array{role_key: string, email: string, name: string, language: string, access_code: string}>}}
     */
    private static function row(int $line, array $cells, Collection $roles, array $columns, string $defaultLanguage, array &$seen): array
    {
        $cell = fn (?int $index): string => $index === null ? '' : trim((string) ($cells[$index] ?? ''));
        $signers = [];
        $emails = [];

        foreach ($roles as $role) {
            $column = $columns[$role->key];
            $email = mb_strtolower($cell($column['email']));
            $name = $cell($column['name']);
            $language = mb_strtolower($cell($column['language']));
            $code = $cell($column['access_code']);

            if ($email === '') {
                return self::invalid($line, __('Rollen :role saknar e-post.', ['role' => $role->label]));
            }

            if (strlen($email) > 255 || Validator::make(['email' => $email], ['email' => ['email']])->fails()) {
                return self::invalid($line, __('E-postadressen för rollen :role är inte giltig.', ['role' => $role->label]));
            }

            if (mb_strlen($name) > 120 || preg_match('/^[^\p{C}]*$/u', $name) !== 1) {
                return self::invalid($line, __('Namnet för rollen :role är för långt eller har tecken som inte går att använda.', ['role' => $role->label]));
            }

            if ($language !== '' && ! in_array($language, ['sv', 'en'], true)) {
                return self::invalid($line, __('Språket för rollen :role ska vara sv eller en.', ['role' => $role->label]));
            }

            if ($role->verification === SignerCheck::AccessCode->value) {
                try {
                    $code = SignerChecks::validAccessCode($code, 'access_code');
                } catch (ValidationException $exception) {
                    return self::invalid($line, __('Åtkomstkod för rollen :role: :why', ['role' => $role->label, 'why' => (string) collect($exception->errors())->flatten()->first()]));
                }
            } else {
                $code = '';
            }

            $emails[] = $email;
            $signers[] = ['role_key' => $role->key, 'email' => $email, 'name' => $name, 'language' => $language === '' ? $defaultLanguage : $language, 'access_code' => $code];
        }

        if (count($signers) > SigningEnvelope::MAX_SIGNERS) {
            return self::invalid($line, __('Högst :max undertecknare.', ['max' => SigningEnvelope::MAX_SIGNERS]));
        }

        if (count(array_unique($emails)) !== count($emails)) {
            return self::invalid($line, __('Samma e-postadress står på mer än en roll. Varje undertecknare behöver en egen.'));
        }

        sort($emails);
        $key = implode('|', $emails);

        if (isset($seen[$key])) {
            return self::invalid($line, __('Samma undertecknare som på rad :row. Dubbletten skickas inte.', ['row' => $seen[$key]]));
        }

        $seen[$key] = $line;

        return ['row' => $line, 'status' => SigningBulkSendRow::PENDING, 'reason' => null, 'payload' => ['signers' => $signers]];
    }

    /** @return array{row: int, status: string, reason: ?string, payload: null} */
    private static function invalid(int $line, string $reason): array
    {
        return ['row' => $line, 'status' => SigningBulkSendRow::INVALID, 'reason' => $reason, 'payload' => null];
    }
}
