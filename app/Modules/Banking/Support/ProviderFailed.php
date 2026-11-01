<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use RuntimeException;

/**
 * The provider could not be reached or answered unexpectedly. The message
 * is safe to store and log: it never contains tokens, session ids or
 * account data.
 */
final class ProviderFailed extends RuntimeException {}
