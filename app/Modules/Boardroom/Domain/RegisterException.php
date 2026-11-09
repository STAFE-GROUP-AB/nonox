<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use RuntimeException;

/**
 * An entry that would make the register impossible: a share held twice,
 * a share outside the issued ranges, a transfer from someone who does not
 * hold the shares. The message is Swedish and shown to the user.
 */
class RegisterException extends RuntimeException {}
