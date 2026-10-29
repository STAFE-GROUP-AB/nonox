<?php

declare(strict_types=1);

use App\Support\Sie\Cp437;
use App\Support\Sie\SieAmount;
use App\Support\Sie\SieChecksum;
use App\Support\Sie\SieDate;
use App\Support\Sie\SieReader;
use App\Support\Sie\SieRecord;
use App\Support\Sie\SieSyntaxError;
use App\Support\Sie\SieText;
use App\Support\Sie\SieTokenizer;
use App\Support\Sie\SieWriter;

function sieFile(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'sie');
    file_put_contents($path, $bytes);

    return $path;
}

/**
 * @return array{0: list<SieRecord>, 1: SieReader}
 */
function readSie(string $bytes): array
{
    $reader = new SieReader(sieFile($bytes));

    return [iterator_to_array($reader->records(), false), $reader];
}

function writeSie(callable $write): string
{
    $stream = fopen('php://memory', 'w+b');
    $writer = new SieWriter($stream);
    $write($writer);
    $writer->finish();
    rewind($stream);

    return (string) stream_get_contents($stream);
}

describe('codepage 437', function () {
    it('maps the Swedish letters both ways', function () {
        expect(Cp437::encode('åäöÅÄÖé'))->toBe("\x86\x84\x94\x8F\x8E\x99\x82")
            ->and(Cp437::decode("\x86\x84\x94\x8F\x8E\x99\x82"))->toBe('åäöÅÄÖé');
    });

    it('is lossless for every character of the codepage', function () {
        foreach (range(0x80, 0xFE) as $byte) {
            expect(Cp437::encode(Cp437::decode(chr($byte))))->toBe(chr($byte));
        }
    });

    it('replaces what the codepage lacks', function () {
        expect(Cp437::encode('Moms – 25 € ”rätt”'))->toBe("Moms - 25 EUR \"r\x84tt\"")
            ->and(Cp437::encode('Kaffe ☕'))->toBe('Kaffe ?')
            ->and(Cp437::encode("rad ett\nrad två\t"))->toBe("rad ett rad tv\x86 ");
    });
});

describe('amounts and dates', function () {
    it('reads amounts into öre without floats', function (string $amount, ?int $ore) {
        expect(SieAmount::toOre($amount))->toBe($ore);
    })->with([
        ['1234.50', 123450],
        ['-1000', -100000],
        ['12.5', 1250],
        ['0.07', 7],
        ['-0.00', 0],
        ['9999999999999999.99', 999999999999999999],
        ['1,00', null],
        ['+1', null],
        ['1.005', null],
        ['1 000', null],
        ['', null],
        ['12345678901234567', null],
    ]);

    it('writes amounts with a point and two decimals', function () {
        expect(SieAmount::format(123450))->toBe('1234.50')
            ->and(SieAmount::format(-5))->toBe('-0.05')
            ->and(SieAmount::format(0))->toBe('0.00');
    });

    it('reads dates as ÅÅÅÅMMDD', function () {
        expect(SieDate::parse('20260229'))->toBeNull()
            ->and(SieDate::parse('2026-02-01'))->toBeNull()
            ->and(SieDate::parse('20280229')?->toDateString())->toBe('2028-02-29');
    });
});

describe('fields', function () {
    it('splits fields the way §5.7 describes', function (string $line, array $tokens) {
        expect(SieTokenizer::tokenize($line))->toBe($tokens);
    })->with([
        'quoted with escaped quotes' => ['#KONTO 1915 "Kassa \"special\""', ['#KONTO', '1915', 'Kassa "special"']],
        'tabs and runs of spaces' => ["#IB\t0   1930\t23780.78", ['#IB', '0', '1930', '23780.78']],
        'object list with pairs' => ['#TRANS 7010 {"1" "456" "7" "47"} 13200.00', ['#TRANS', '7010', ['1', '456', '7', '47'], '13200.00']],
        'empty object list with a space' => ['#TRANS 1910 { } -1000.00', ['#TRANS', '1910', [], '-1000.00']],
        'empty fields keep their position' => ['#VER "" "" 20251216 "Porto"', ['#VER', '', '', '20251216', 'Porto']],
    ]);

    it('refuses a quote or brace that is never closed', function (string $line) {
        expect(fn () => SieTokenizer::tokenize($line))->toThrow(SieSyntaxError::class);
    })->with(['#KONTO 1910 "Kassa', '#TRANS 1910 {1 "2" 10.00', '#TRANS 1910 {{}} 1']);
});

describe('the control sum', function () {
    it('covers labels and field contents, not whitespace, quotes or braces', function () {
        $sameContent = [
            "#KONTO 1915 \"Kassa \\\"special\\\"\"\r\n#VER A 1 20260101\r\n{\r\n#TRANS 1910 {1 \"01\"} -10.00\r\n}\r\n",
            "#KONTO\t\"1915\"   \"Kassa \\\"special\\\"\"\n#VER A 1 20260101\n{\n   #TRANS 1910 {\"1\" \"01\"} \"-10.00\"\n}\n",
        ];

        foreach ($sameContent as $body) {
            $expected = crc32('#KONTO1915Kassa "special"#VERA120260101#TRANS1910101-10.00');
            [, $reader] = readSie("#FLAGGA 0\r\n#KSUMMA\r\n{$body}#KSUMMA {$expected}\r\n");

            expect($reader->checksum())->toBe(SieChecksum::Valid)
                ->and($reader->problems())->toBe([]);
        }
    });

    it('is computed over codepage 437 bytes, even when a file is written in UTF-8', function () {
        $expected = crc32("#FNAMNK\x94pmannen AB");

        [, $pc8] = readSie("#KSUMMA\n#FNAMN \"K\x94pmannen AB\"\n#KSUMMA {$expected}\n");
        [$records, $utf8] = readSie("#KSUMMA\n#FNAMN \"Köpmannen AB\"\n#KSUMMA {$expected}\n");

        expect($pc8->checksum())->toBe(SieChecksum::Valid)
            ->and($utf8->checksum())->toBe(SieChecksum::Valid)
            ->and($utf8->utf8Lines())->toBe(1)
            ->and($records[0]->field(0))->toBe('Köpmannen AB');
    });

    it('notices a changed amount and a file that is cut short', function () {
        $file = writeSie(function (SieWriter $writer) {
            $writer->record('#FLAGGA', [0]);
            $writer->beginChecksum();
            $writer->record('#IB', [0, 1930, '100.00']);
        });

        [, $reader] = readSie($file);
        expect($reader->checksum())->toBe(SieChecksum::Valid);

        [, $reader] = readSie(str_replace('100.00', '100.01', $file));
        expect($reader->checksum())->toBe(SieChecksum::Invalid)
            ->and($reader->problems())->toBe(['Kontrollsumman (#KSUMMA) stämmer inte. Filen har ändrats eller skadats efter att den skapades.']);

        [, $reader] = readSie(substr($file, 0, strrpos($file, '#KSUMMA')));
        expect($reader->checksum())->toBe(SieChecksum::Unterminated);

        [, $reader] = readSie("#FLAGGA 0\n#IB 0 1930 100.00\n");
        expect($reader->checksum())->toBe(SieChecksum::Absent)
            ->and($reader->problems())->toBe([]);
    });
});

describe('reading', function () {
    it('reads vouchers with their rows, whatever the line endings and blank lines', function () {
        [$records, $reader] = readSie("\xEF\xBB\xBF#FLAGGA 0\r\n\r\n#VER A 1 20260101 \"Porto\"\r\n{\r\n   #TRANS 1910 {} -1000.00\r\n\r\n   #RTRANS 2640 {} 200.00\n   #TRANS 2640 {} 200.00\n   #BTRANS 6110 {} 800.00\n#TRANS 6250 {} 800.00\n}\n#OKAND 1 2 3\n");

        expect(array_map(fn (SieRecord $record) => $record->label, $records))->toBe(['#FLAGGA', '#VER', '#OKAND'])
            ->and(array_map(fn (SieRecord $row) => $row->label, $records[1]->rows))->toBe(['#TRANS', '#RTRANS', '#TRANS', '#BTRANS', '#TRANS'])
            ->and($records[1]->field(3))->toBe('Porto')
            ->and($records[1]->rows[0]->objects(1))->toBe([])
            ->and($reader->problems())->toBe([]);
    });

    it('reports lines it cannot use, with their line numbers', function () {
        [$records, $reader] = readSie("#FLAGGA 0\nskräp\n#KONTO 1910 \"Kassa\n#VER A 1 20260101\n#KONTO 1930 Bank\n#VER A 2 20260102\n{\n#TRANS 1930 {} 1.00\n");

        expect(array_map(fn (SieRecord $record) => $record->label, $records))->toBe(['#FLAGGA', '#KONTO'])
            ->and($reader->problems())->toBe([
                'Rad 2: raden börjar inte med en etikett som #VER eller #KONTO.',
                'Rad 3: raden går inte att läsa (ett citattecken eller en klammer är inte avslutad).',
                'Rad 4: verifikationen saknar rader inom { }.',
                'Rad 6: filen tar slut mitt i en verifikation. Filen verkar vara ofullständig.',
            ]);
    });
});

describe('writing', function () {
    it('quotes text, leaves codes bare and drops empty trailing fields', function () {
        $file = writeSie(function (SieWriter $writer) {
            $writer->record('#KONTO', [1510, new SieText('Kundfordringar')]);
            $writer->record('#ORGNR', ['556334-3689']);
            $writer->voucher(['A', 1, '20260101', new SieText('Porto "brev"'), null, null], [
                ['#TRANS', [1910, [], '-10.00']],
                ['#TRANS', [6250, ['1', new SieText('0123')], '10.00', null, new SieText('C:\\mapp\\')]],
            ]);
        });

        expect($file)->toBe(implode("\r\n", [
            '#KONTO 1510 "Kundfordringar"',
            '#ORGNR 556334-3689',
            '#VER A 1 20260101 "Porto \"brev\""',
            '{',
            '   #TRANS 1910 {} -10.00',
            '   #TRANS 6250 {1 "0123"} 10.00 "" "C:\mapp/"',
            '}',
        ])."\r\n");
    });

    it('reads back exactly what it wrote, with a valid control sum', function () {
        mt_srand(11);
        $alphabet = ['a', 'Ö', 'å', ' ', '"', '\\', '{', '}', '#', '-', '1', "\t", 'é'];
        $texts = [];

        foreach (range(1, 200) as $attempt) {
            $text = '';

            foreach (range(1, mt_rand(0, 12)) as $character) {
                $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }

            $texts[] = $text;
        }

        $file = writeSie(function (SieWriter $writer) use ($texts) {
            $writer->record('#FLAGGA', [0]);
            $writer->beginChecksum();

            foreach ($texts as $index => $text) {
                $writer->record('#KONTO', [1000 + $index, new SieText($text)]);
            }
        });

        [$records, $reader] = readSie($file);
        $expected = array_map(fn (string $text) => (string) preg_replace('/\\\\(?=$|")/', '/', str_replace("\t", ' ', $text)), $texts);

        expect($reader->checksum())->toBe(SieChecksum::Valid)
            ->and($reader->problems())->toBe([])
            ->and(array_map(fn (SieRecord $record) => $record->field(1), array_slice($records, 1)))->toBe($expected);
    });
});
