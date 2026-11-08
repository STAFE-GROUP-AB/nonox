<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use RuntimeException;

/** A split the register refuses, with the Swedish reason for the person (ComponentSplit). */
final class ComponentSplitRefused extends RuntimeException {}
