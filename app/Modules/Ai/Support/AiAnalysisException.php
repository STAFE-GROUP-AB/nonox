<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use RuntimeException;

/**
 * An analysis could not be made. The message is written for the person in
 * front of the screen.
 */
final class AiAnalysisException extends RuntimeException {}
