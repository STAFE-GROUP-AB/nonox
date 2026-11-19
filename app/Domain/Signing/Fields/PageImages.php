<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Models\SigningDocumentVersion;
use App\Models\SigningRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The pages of a document version as PNG images, for the signer page with
 * fields (ADR-046 amendment, gap 2, package S6). Rendered on the server by
 * the PDF engine (no PDF viewer on the public page, so its CSP stays as it
 * is), once per version: the images and a small manifest are kept on the
 * private disk next to the envelope's files, under the version's SHA-256,
 * and served from the same origin behind the signer's token.
 */
final class PageImages
{
    /** The scale the pages are rendered at (1 point = 1.5 pixels); the engine caps the pixels per page. */
    public const float SCALE = 1.5;

    /** The render lock's lifetime: longer than the job's timeout (RenderSigningPages::$timeout = 300). */
    public const int LOCK_SECONDS = 600;

    public function __construct(private readonly PdfEngine $engine) {}

    /** The directory of a version's page images. */
    public static function directory(int $companyId, string $sha256): string
    {
        return "crm-signing/{$companyId}/sidor/{$sha256}";
    }

    /**
     * The pages of a version as rendered: number and size in 1/100 PDF points (the aspect of the image), or null
     * while they are not rendered (the queued RenderSigningPages does that; a request never renders).
     *
     * @return ?list<array{page: int, width: int, height: int}>
     */
    public function pages(SigningDocumentVersion $version): ?array
    {
        return $this->manifest($version);
    }

    /**
     * Renders a version's pages once (RenderSigningPages). The lock is only tried, never waited for: a version
     * another worker is rendering is left to it.
     *
     * @return ?list<array{page: int, width: int, height: int}> null when another worker holds the lock
     *
     * @throws PdfEngineException when the engine is missing or fails (the job is retried)
     */
    public function render(SigningDocumentVersion $version): ?array
    {
        $manifest = $this->manifest($version);

        if ($manifest !== null) {
            return $manifest;
        }

        $lock = Cache::lock("signing-pages:{$version->company_id}:{$version->sha256}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->manifest($version) ?? $this->draw($version);
        } finally {
            $lock->release();
        }
    }

    /** The PNG bytes of one page, or null when it is not rendered or does not exist. */
    public function image(SigningDocumentVersion $version, int $page): ?string
    {
        $path = self::directory($version->company_id, $version->sha256).'/'.sprintf('page-%04d.png', $page);
        $bytes = Storage::disk(SigningRequest::DISK)->exists($path) ? Storage::disk(SigningRequest::DISK)->get($path) : null;

        return is_string($bytes) && str_starts_with($bytes, "\x89PNG\r\n\x1a\n") ? $bytes : null;
    }

    /** @return ?list<array{page: int, width: int, height: int}> */
    private function manifest(SigningDocumentVersion $version): ?array
    {
        $disk = Storage::disk(SigningRequest::DISK);
        $path = self::directory($version->company_id, $version->sha256).'/manifest.json';

        if (! $disk->exists($path)) {
            return null;
        }

        $pages = json_decode((string) $disk->get($path), true);

        return is_array($pages) && $pages !== [] ? array_values(array_map(fn (array $page): array => ['page' => (int) $page['page'], 'width' => (int) $page['width'], 'height' => (int) $page['height']], $pages)) : null;
    }

    /** @return list<array{page: int, width: int, height: int}> */
    private function draw(SigningDocumentVersion $version): array
    {
        $disk = Storage::disk(SigningRequest::DISK);
        $directory = self::directory($version->company_id, $version->sha256);
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-signing-pages-'.Str::uuid()->toString();
        File::ensureDirectoryExists($work, 0700);

        try {
            $pages = [];

            foreach ($this->engine->renderPages($disk->path($version->path), $work, null, self::SCALE) as $rendered) {
                $disk->put($directory.'/'.sprintf('page-%04d.png', $rendered->page), (string) file_get_contents($rendered->path));
                $pages[] = ['page' => $rendered->page, 'width' => (int) round($rendered->widthPt * 100), 'height' => (int) round($rendered->heightPt * 100)];
            }

            // The manifest last: a render that stopped half-way is done again.
            $disk->put($directory.'/manifest.json', (string) json_encode($pages));

            return $pages;
        } finally {
            File::deleteDirectory($work);
        }
    }
}
