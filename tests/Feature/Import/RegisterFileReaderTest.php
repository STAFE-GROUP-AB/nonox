<?php

declare(strict_types=1);

use App\Migration\Import\RegisterFileReader;

/*
 * ADR-076, S1: the reader. Synthetic fixtures only (tests/Fixtures/import).
 */
function registerImportFixture(string $name): string
{
    return base_path("tests/Fixtures/import/{$name}");
}

function registerTempFile(string $contents, string $extension = 'csv'): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.'.$extension;
    file_put_contents($path, $contents);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

it('reads a comma separated UTF-8 file', function () {
    $table = RegisterFileReader::open(registerImportFixture('kunder-spiris-lik.csv'));

    expect($table->format)->toBe('csv')
        ->and($table->encoding)->toBe('UTF-8')
        ->and($table->delimiter)->toBe(',')
        ->and($table->headers)->toContain('CustomerNumber', 'Corp.ID no.')
        ->and(iterator_count($table->rows()))->toBe(4);
});

it('detects Windows-1252 and the semicolon', function () {
    $table = RegisterFileReader::open(registerImportFixture('kunder-semikolon-windows1252.csv'));
    $rows = iterator_to_array($table->rows(), false);

    expect($table->encoding)->toBe('Windows-1252')
        ->and($table->delimiter)->toBe(';')
        ->and($table->headers)->toBe(['Kundnr', 'Företag', 'Orgnr', 'Ort', 'Villkor'])
        ->and($rows[0][1])->toBe('Åkerströms Åkeri AB')
        ->and($rows[1][3])->toBe('Växjö');
});

it('reads CP437 when the caller says so', function () {
    $path = registerTempFile(iconv('UTF-8', 'CP437', "Namn;Ort\nÅke Ö;Växjö\n"));
    $table = RegisterFileReader::open($path, ['encoding' => 'CP437']);

    expect(iterator_to_array($table->rows(), false)[0])->toBe(['Åke Ö', 'Växjö']);
});

it('strips a byte order mark and reads tab and pipe delimited files', function () {
    $bom = RegisterFileReader::open(registerImportFixture('kunder-med-formler.csv'));
    $tab = RegisterFileReader::open(registerTempFile("Namn\tOrt\nA\tB\n", 'tsv'));
    $pipe = RegisterFileReader::open(registerTempFile("Namn|Ort\nA|B\n", 'txt'));

    expect($bom->headers[0])->toBe('Name')
        ->and($tab->delimiter)->toBe("\t")
        ->and($pipe->delimiter)->toBe('|')
        ->and(iterator_to_array($pipe->rows(), false)[0])->toBe(['A', 'B']);
});

it('ignores a delimiter inside quotes when detecting', function () {
    $table = RegisterFileReader::open(registerTempFile("\"Namn, med komma\";Ort\n\"A, B\";C\n"));

    expect($table->delimiter)->toBe(';')
        ->and(iterator_to_array($table->rows(), false)[0])->toBe(['A, B', 'C']);
});

it('keeps formula looking cells as plain text and never evaluates them', function () {
    $rows = iterator_to_array(RegisterFileReader::open(registerImportFixture('kunder-med-formler.csv'))->rows(), false);

    expect(array_column($rows, 0))->toBe(['=HYPERLINK("http://example.test","klicka")', '+kommando', '-minus', '@sum']);
});

it('reads the first worksheet of an xlsx file, or the one chosen', function () {
    $first = RegisterFileReader::open(registerImportFixture('kunder-egen.xlsx'));
    $second = RegisterFileReader::open(registerImportFixture('kunder-egen.xlsx'), ['sheet' => 'Andra']);

    expect($first->format)->toBe('xlsx')
        ->and($first->sheets)->toBe(['Kunder', 'Andra'])
        ->and($first->sheet)->toBe('Kunder')
        ->and($first->headers)->toBe(['Namn', 'Organisationsnummer', 'E-post'])
        ->and(iterator_to_array($first->rows(), false)[1][0])->toBe('=1+1')
        ->and($second->headers)->toBe(['Namn'])
        ->and(iterator_to_array($second->rows()))->toBe([2 => ['Blad Två AB']]);
});

it('refuses files over the size limit and unreadable workbooks', function () {
    $big = registerTempFile('');
    $handle = fopen($big, 'r+');
    ftruncate($handle, RegisterFileReader::MAX_BYTES + 1);
    fclose($handle);

    expect(fn () => RegisterFileReader::open($big))->toThrow(RuntimeException::class, 'större än 20 MB')
        ->and(fn () => RegisterFileReader::open(registerTempFile("PK\x03\x04trasig", 'xlsx')))->toThrow(RuntimeException::class);
});

it('skips empty rows and matches headers regardless of case, spaces and underscores', function () {
    $table = RegisterFileReader::open(registerTempFile("Namn;Ort\n;\nA;B\n\n"));

    expect(iterator_count($table->rows()))->toBe(1)
        ->and(RegisterFileReader::normaliseHeader(' Corp.ID no. '))->toBe(RegisterFileReader::normaliseHeader('corp_id_NO'));
});
