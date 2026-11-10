<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Concerns;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * What every Tillverkning page shares: the current user and company, the
 * permission checks (ManufacturingPolicy), and running an action with its
 * errors shown on the form and in a Swedish toast.
 *
 * @mixin Component
 */
trait ManufacturingPage
{
    use InCurrentCompany;
    use ShowsToasts;

    protected function can(string $ability): bool
    {
        return Gate::forUser($this->user())->allows($ability, [Operation::class, $this->company()]);
    }

    /**
     * @param  Closure(): mixed  $action
     */
    protected function attempt(Closure $action, string $success, string $prefix = 'form.'): bool
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["{$prefix}{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return false;
        }

        $this->resetErrorBag();

        if ($success !== '') {
            $this->toast($success);
        }

        return true;
    }

    /** Percent with one decimal, from basis points: 1250 → "12,5 %". */
    public static function percent(?int $bp): string
    {
        if ($bp === null) {
            return '–';
        }

        return str_replace('.', ',', (string) BigDecimal::of($bp)->dividedBy(100, 1, RoundingMode::HalfUp)).' %';
    }

    /** Minutes as hours and minutes: 135 → "2 h 15 min". */
    public static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => __(':min min', ['min' => $rest]),
            $rest === 0 => __(':h h', ['h' => $hours]),
            default => __(':h h :min min', ['h' => $hours, 'min' => $rest]),
        };
    }

    /** Basis points as the percent a form shows: 1250 → "12,5", 0 → "". */
    protected static function percentInput(int $bp): string
    {
        return $bp === 0 ? '' : str_replace('.', ',', rtrim(rtrim((string) BigDecimal::of($bp)->dividedBy(100, 2, RoundingMode::HalfUp), '0'), '.'));
    }
}
