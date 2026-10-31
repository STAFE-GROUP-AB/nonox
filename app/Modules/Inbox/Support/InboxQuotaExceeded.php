<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use RuntimeException;

/**
 * The company has received more mail in the last hour than WorkQuota
 * allows. The cloud answers 429 so Mailgun delivers again later; IMAP
 * stops and fetches the rest on the next run.
 */
final class InboxQuotaExceeded extends RuntimeException {}
