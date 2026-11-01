<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

use RuntimeException;

class MailboxUnavailable extends RuntimeException {}
