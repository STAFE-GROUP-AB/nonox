<?php

declare(strict_types=1);

namespace App\Support\Guide;

/**
 * "Frågor vi får" on Företagsguiden's start page: the questions founders
 * actually ask, each answered by an article. A test checks that every
 * article exists; a question whose article is not written is left out.
 */
final class GuideQuestions
{
    /** @var list<array{question: string, article: string}> */
    public const array QUESTIONS = [
        ['question' => 'Hur mycket av det jag fakturerar är moms?', 'article' => 'moms-och-skatt/sa-fungerar-moms'],
        ['question' => 'Hur får jag in det jag köpt innan företaget fanns?', 'article' => 'starta/inkop-fore-start'],
        ['question' => 'Hur mycket ska jag sätta undan?', 'article' => 'moms-och-skatt/satt-undan-pengar'],
        ['question' => 'Aktiebolag eller enskild firma?', 'article' => 'starta/valj-bolagsform'],
        ['question' => 'Hur länge måste jag spara kvitton?', 'article' => 'ansvar-och-regler/arkivering'],
        ['question' => 'Vad händer om jag är sen?', 'article' => 'ansvar-och-regler/nar-nagot-gar-fel'],
        ['question' => 'Måste jag ha kassaregister?', 'article' => 'ansvar-och-regler/kassaregister-och-personalliggare'],
        ['question' => 'Får jag låna pengar av mitt aktiebolag?', 'article' => 'starta/foretagskonto-och-pengar'],
    ];

    /** @return list<array{question: string, article: Article}> the questions whose article is written */
    public static function answered(): array
    {
        $answered = [];

        foreach (self::QUESTIONS as $item) {
            [$section, $slug] = explode('/', $item['article'], 2);
            $article = Guide::article($section, $slug, false);

            if ($article !== null) {
                $answered[] = ['question' => $item['question'], 'article' => $article];
            }
        }

        return $answered;
    }
}
