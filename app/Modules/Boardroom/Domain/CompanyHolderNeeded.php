<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

/**
 * A reverse split passes whole new shares to the company itself (ABL 4 kap.
 * 50 §), and the entry does not name the company as holder yet. Recording
 * the entry then adds the company as holder and folds again.
 */
final class CompanyHolderNeeded extends RegisterException {}
