<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use RuntimeException;

/** A field value that breaks its field's rules; the message is the Swedish text the signer sees. */
final class FieldRefused extends RuntimeException {}
