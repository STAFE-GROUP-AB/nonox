<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Http;

use App\Modules\Inbox\Actions\AcceptInboundMail;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a message's original and attachments. Nothing is ever rendered
 * as a page on NONOX's origin: PDFs and pictures are shown inline in a
 * sandbox (CSP sandbox, nosniff), everything else is a download with a
 * neutral type. Blocked attachments are never served. The company scope
 * makes another company's message answer 404.
 */
class InboxFileController
{
    public function original(string $message): StreamedResponse
    {
        $found = InboxMessage::query()->findOrFail($message);
        Gate::authorize('view', $found);

        return Storage::disk(AcceptInboundMail::DISK)->download($found->eml_path, 'meddelande.eml', [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    public function attachment(string $message, int $attachment): StreamedResponse
    {
        $found = InboxMessage::query()->findOrFail($message);
        Gate::authorize('view', $found);

        $file = InboxAttachment::query()->where('inbox_message_id', $found->id)->findOrFail($attachment);
        abort_if($file->isBlocked(), 404);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'",
        ];

        if ($file->isPreviewable()) {
            return Storage::disk(AcceptInboundMail::DISK)->response($file->path, $file->filename, [...$headers, 'Content-Type' => $file->mime], 'inline');
        }

        return Storage::disk(AcceptInboundMail::DISK)->download($file->path, $file->filename, [...$headers, 'Content-Type' => 'application/octet-stream']);
    }
}
