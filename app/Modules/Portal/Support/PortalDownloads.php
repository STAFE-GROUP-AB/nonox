<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Modules\Portal\Models\PortalAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads from the portal (ADR-075 §8): always "attachment", never
 * inline, with nosniff and a file name built by HeaderUtils with an ASCII
 * fallback, so a customer's file name can never break the header.
 */
final class PortalDownloads
{
    public static function bytes(string $bytes, string $name, string $mime): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => self::disposition($name),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function file(PortalAttachment $file): Response
    {
        $bytes = Storage::disk(PortalAttachment::DISK)->get($file->path);
        abort_unless(is_string($bytes), 404);

        return self::bytes($bytes, $file->original_name, $file->mime);
    }

    public static function disposition(string $name): string
    {
        $name = str_replace(['/', '\\', '"', "\r", "\n"], '', $name);
        $name = $name === '' ? 'fil' : $name;
        $fallback = str_replace('%', '', Str::ascii($name));

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback === '' ? 'fil' : $fallback);
    }
}
