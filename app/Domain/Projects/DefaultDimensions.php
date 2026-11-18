<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\CostCenter;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

/**
 * The default cost centre and project of an article or a customer (ADR-081
 * gap 6), given by code like every dimension input (ADR-009) and looked up
 * inside the current company. Only the keys present in the input are
 * returned, so a save that does not mention them leaves them as they are.
 * Must run inside the company.
 */
final class DefaultDimensions
{
    /**
     * @param  array<string, mixed>  $input  default_cost_center and default_project (codes, '' for none)
     * @return array{default_cost_center_id?: ?int, default_project_id?: ?int}
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input): array
    {
        $attributes = [];

        if (array_key_exists('default_cost_center', $input)) {
            $attributes['default_cost_center_id'] = self::lookup(CostCenter::class, $input['default_cost_center'], 'default_cost_center', __('Kostnadsstället :code finns inte.', ['code' => trim((string) $input['default_cost_center'])]));
        }

        if (array_key_exists('default_project', $input)) {
            $attributes['default_project_id'] = self::lookup(Project::class, $input['default_project'], 'default_project', __('Projektet :code finns inte.', ['code' => trim((string) $input['default_project'])]));
        }

        return $attributes;
    }

    /** @param  class-string<CostCenter|Project>  $model */
    private static function lookup(string $model, mixed $code, string $field, string $message): ?int
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        $id = $model::query()->where('code', $code)->value('id');

        if ($id === null) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return (int) $id;
    }
}
