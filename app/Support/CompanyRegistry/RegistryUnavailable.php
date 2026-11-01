<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

use RuntimeException;

/** The register did not answer, refused the credentials or answered with something unreadable. */
final class RegistryUnavailable extends RuntimeException {}
