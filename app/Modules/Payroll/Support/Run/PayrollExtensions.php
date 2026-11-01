<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Run;

use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use Closure;
use Illuminate\Support\Collection;

/**
 * Where other modules plug into a payroll run (ADR-033 §1): context
 * extensions that add data or price inputs before the calculation, and
 * steps that run after payroll's own. Each module decides itself, per
 * company, whether it is on; payroll without them works as before.
 */
final class PayrollExtensions
{
    /** @var list<Closure(): ContextExtension> */
    private array $extensions = [];

    /** @var list<Closure(): Step> */
    private array $steps = [];

    /** @var ?list<ContextExtension> */
    private ?array $resolved = null;

    /** @param  Closure(): ContextExtension  $extension */
    public function addContextExtension(Closure $extension): void
    {
        $this->extensions[] = $extension;
        $this->resolved = null;
    }

    /** @param  Closure(): Step  $step */
    public function addStep(Closure $step): void
    {
        $this->steps[] = $step;
    }

    /** @param  Collection<int, Employment>  $employments */
    public function prepare(PayrollRun $run, Collection $employments): void
    {
        foreach ($this->contextExtensions() as $extension) {
            $extension->prepare($run, $employments);
        }
    }

    public function extend(PayrollContext $context, PayrollRun $run, Employment $employment): PayrollContext
    {
        foreach ($this->contextExtensions() as $extension) {
            $context = $extension->extend($context, $run, $employment);
        }

        return $context;
    }

    /** @return list<Step> */
    public function steps(): array
    {
        return array_map(fn (Closure $step): Step => $step(), $this->steps);
    }

    /** @return list<ContextExtension> */
    private function contextExtensions(): array
    {
        return $this->resolved ??= array_map(fn (Closure $extension): ContextExtension => $extension(), $this->extensions);
    }
}
