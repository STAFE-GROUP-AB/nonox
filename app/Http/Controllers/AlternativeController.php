<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Landing\Comparison;
use App\Support\Landing\LandingPage;
use Illuminate\View\View;

/**
 * "Alternativ till …" pages (ADR-020): NONOX compared with one other
 * program, with what it costs a company with five employees, the feature
 * table and how to move from that program.
 */
class AlternativeController
{
    public function __invoke(string $program): View
    {
        $other = Comparison::program($program);
        abort_if($other === null, 404);

        $nonox = Comparison::data()['programs'][0];
        $source = collect(MigrationGuideController::sources())->firstWhere('slug', $program)
            ?? collect(MigrationGuideController::sources())->last();

        $faqs = [
            ['q' => "Är NONOX ett bra alternativ till {$other['name']}?", 'a' => 'NONOX har det ett svenskt företag behöver: bokföring, fakturering, lön, moms, bokslut och årsredovisning. '.self::differenceText($other)],
            ['q' => "Kan jag flytta min bokföring från {$other['name']}?", 'a' => "Ja. Exportera en SIE4-fil för varje räkenskapsår från {$other['name']} och läs in dem i NONOX. Hela historiken följer med, och NONOX stämmer av varje år innan något sparas. Flyttguiden visar alla steg."],
            ['q' => "Vad kostar NONOX jämfört med {$other['name']}?", 'a' => 'Att köra NONOX själv kostar ingenting. Med drift hos oss kostar det 995 kr om året för upp till 10 anställda, alla delar inräknade. Tabellen visar listpriset hos '.$other['name'].' för ett företag med fem anställda.'],
            ['q' => 'Följer NONOX Bokföringslagen?', 'a' => 'NONOX är byggt efter Bokföringslagen och Bokföringsnämndens regler, med verifikationer som inte kan ändras i efterhand, behandlingshistorik, systemdokumentation och arkivering.'],
            ['q' => 'Är jag inlåst i NONOX?', 'a' => 'Nej. All bokföring kan exporteras som SIE4 och som ett komplett arkivpaket när du vill, och koden är öppen.'],
        ];

        return view('start.alternative', [
            ...LandingPage::data(),
            'other' => $other,
            'nonox' => $nonox,
            'saving' => max(0, $other['per_year'] - $nonox['per_year']),
            'source' => $source,
            'altFaqs' => $faqs,
            'faqSchema' => $faqs,
            'pageTitle' => "Gratis alternativ till {$other['name']} – NONOX, bokföring med öppen källkod",
            'pageDescription' => "Letar du efter ett alternativ till {$other['name']}? NONOX är ett komplett svenskt bokföringsprogram med öppen källkod: bokföring, fakturering, lön och bokslut. Gratis att köra själv, 995 kr om året med drift.",
        ]);
    }

    /**
     * What sets NONOX apart from the other program, built from the rows in
     * the comparison data, so the page never claims a difference the
     * other program's own pages contradict.
     *
     * @param  array{slug: string, name: string}  $other
     */
    private static function differenceText(array $other): string
    {
        $claims = [
            'Öppen källkod' => 'att NONOX är öppen källkod',
            'Alla moduler ingår i priset' => 'att alla delar ingår i priset',
            'Kör på egen server' => 'att du kan köra det själv helt gratis',
        ];

        $differences = [];
        $shared = 0;

        foreach (Comparison::data()['features'] as $row) {
            if (! isset($claims[$row['name']])) {
                continue;
            }

            // Only a "no" on the other program's own pages is a difference; "unknown" claims nothing.
            $value = $row['values'][$other['slug']] ?? 'unknown';

            if ($value === 'no') {
                $differences[] = $claims[$row['name']];
            }

            if ($value === 'yes' && in_array($row['name'], ['Öppen källkod', 'Kör på egen server'], true)) {
                $shared++;
            }
        }

        if ($differences === [] && $shared === 2) {
            return "Precis som {$other['name']} kan NONOX köras själv med öppen källkod. Tabellen nedan visar skillnaderna funktion för funktion.";
        }

        if ($differences === []) {
            return 'Tabellen nedan visar skillnaderna funktion för funktion.';
        }

        $last = array_pop($differences);
        $list = $differences === [] ? $last : implode(', ', $differences)." och {$last}";

        return "Skillnaden mot {$other['name']} är {$list}.";
    }
}
