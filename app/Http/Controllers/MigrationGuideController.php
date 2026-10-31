<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Migration\Fortnox\FortnoxOAuth;
use App\Support\Landing\LandingPage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public moving guide at /flytta-hit: step by step from another
 * bookkeeping program to NONOX. What to export from each program is
 * written in docs/migration/kallor (one Markdown file per program, numbered
 * for order), the only place other programs are named (AGENTS.md §1.1).
 */
class MigrationGuideController
{
    public function __invoke(): View
    {
        return view('start.migration-guide', [
            ...LandingPage::data(),
            'sources' => self::sources(),
            'directFetch' => FortnoxOAuth::isConfigured(),
            'pageTitle' => 'Flytta till NONOX – steg för steg',
            'pageDescription' => 'Så flyttar du bokföring, fakturor och lön från ditt gamla bokföringsprogram till NONOX, steg för steg och utan att något går förlorat.',
        ]);
    }

    /** @return list<array{slug: string, name: string, html: string}> */
    public static function sources(): array
    {
        $files = glob(base_path('docs/migration/kallor/*.md')) ?: [];
        sort($files);

        return array_map(function (string $file): array {
            $markdown = (string) file_get_contents($file);
            $name = preg_match('/^# (.+)$/m', $markdown, $match) === 1 ? trim($match[1]) : basename($file, '.md');
            $body = (string) preg_replace('/^# .+\R+/', '', $markdown, 1);

            return [
                'slug' => (string) preg_replace('/^\d+-/', '', basename($file, '.md')),
                'name' => $name,
                'html' => Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            ];
        }, $files);
    }
}
