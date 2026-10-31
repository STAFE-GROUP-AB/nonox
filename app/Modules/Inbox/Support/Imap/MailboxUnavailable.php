<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

use RuntimeException;

final class MailboxUnavailable extends RuntimeException {}
