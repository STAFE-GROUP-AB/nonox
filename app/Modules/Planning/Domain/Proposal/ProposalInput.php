<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

use Carbon\CarbonImmutable;

/**
 * Everything one proposal run needs, loaded once (ProposalLoader): the open
 * shifts of the period, the employees who may get them, the company's
 * working-time and minors' rules and the weights.
 */
final readonly class ProposalInput
{
    /**
     * @param  list<ProposalSlot>  $slots
     * @param  list<ProposalCandidate>  $candidates
     * @param  array<string, array<string, mixed>>  $rules  resolved working_time_rules
     * @param  array<string, array<string, mixed>>  $minorsRules  resolved minors_rules
     * @param  CarbonImmutable  $periodFrom  first day of the period
     * @param  CarbonImmutable  $periodTo  last day of the period
     */
    public function __construct(
        public array $slots,
        public array $candidates,
        public CarbonImmutable $periodFrom,
        public CarbonImmutable $periodTo,
        public array $rules = [],
        public bool $minorsEnabled = false,
        public array $minorsRules = [],
        public ProposalWeights $weights = new ProposalWeights,
        public int $fairnessWeeks = 8,
        public string $unsocialFrom = '19:00',
        public string $unsocialTo = '07:00',
        public int $maxSwaps = ProposalEngine::MAX_SWAPS,
    ) {}
}
