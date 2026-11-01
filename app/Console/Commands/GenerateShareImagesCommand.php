<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Landing\ShareCards;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Renders the share images of the public pages (ShareCards) to public/og
 * with headless Chrome. Run it locally after changing a page's heading and
 * commit the images; the server never needs a browser.
 */
class GenerateShareImagesCommand extends Command
{
    protected $signature = 'og:images {--chrome= : Sökväg till Chrome eller Chromium} {--only= : Bara den här bilden, till exempel start}';

    protected $description = 'Ritar delningsbilderna för de publika sidorna till public/og';

    private const string MAC_CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

    public function handle(): int
    {
        $option = $this->option('chrome');
        $chrome = is_string($option) && $option !== '' ? $option : (getenv('CHROME_PATH') ?: self::MAC_CHROME);

        if (! is_file($chrome)) {
            $this->error('Hittar inte Chrome. Ange sökvägen med --chrome eller CHROME_PATH.');

            return self::FAILURE;
        }

        $target = public_path(ShareCards::DIRECTORY);
        $work = storage_path('framework/og-'.uniqid());
        File::ensureDirectoryExists($target);
        File::ensureDirectoryExists($work);

        $only = $this->option('only');
        $cards = ShareCards::all();

        if (is_string($only) && $only !== '') {
            $cards = array_intersect_key($cards, [$only => true]);
        }

        try {
            foreach ($cards as $key => $card) {
                $this->line("Ritar {$key}.png...");

                $html = "{$work}/{$key}.html";
                File::put($html, view('og.card', [
                    'card' => $card,
                    'fonts' => 'file://'.public_path('fonts/inter'),
                    'logo' => 'file://'.public_path('brand/nonox-light.svg'),
                    'size' => $this->fontSize(mb_strlen("{$card['title']} {$card['accent']}")),
                ])->render());

                $result = Process::timeout(60)->run([
                    $chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
                    '--allow-file-access-from-files', '--window-size=1200,630', '--virtual-time-budget=2000',
                    "--screenshot={$target}/{$key}.png", "file://{$html}",
                ]);

                if (! $result->successful() || ! is_file("{$target}/{$key}.png")) {
                    $this->error("Kunde inte rita {$key}.png: ".trim($result->errorOutput()));

                    return self::FAILURE;
                }
            }
        } finally {
            File::deleteDirectory($work);
        }

        $this->comment(count($cards).' delningsbilder i public/og.');

        return self::SUCCESS;
    }

    /** A long heading gets a smaller size so it keeps to three lines beside the ring. */
    private function fontSize(int $length): int
    {
        if ($length > 60) {
            return 56;
        }

        return $length > 44 ? 66 : 78;
    }
}
