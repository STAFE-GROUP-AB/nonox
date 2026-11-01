<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Accounting\SaveDimensionObject;
use App\Domain\Accounting\Dimension;
use App\Models\CostCenter;
use App\Models\Project;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ApiInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cost centres (kostnadsställen) and projects over the API (ADR-032),
 * through SaveDimensionObject like the UI. The code of an existing object
 * never changes; it can be renamed and deactivated.
 */
final class DimensionController
{
    use ActsAsCredential;

    public function costCenters(Request $request): JsonResponse
    {
        $query = CostCenter::query()->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')));

        return CursorList::respond($request, $query, fn (CostCenter $object): array => ApiPresenter::dimensionObject($object));
    }

    public function costCenter(CostCenter $costCenter): JsonResponse
    {
        return CursorList::one(ApiPresenter::dimensionObject($costCenter));
    }

    public function storeCostCenter(Request $request, SaveDimensionObject $save): JsonResponse
    {
        $object = $save($this->user(), $this->company(), Dimension::CostCenter, $this->input($request, true));

        return CursorList::one(ApiPresenter::dimensionObject($object->refresh()), 201)
            ->header('Location', route('connect.api.cost-centers.show', $object->id));
    }

    public function updateCostCenter(Request $request, CostCenter $costCenter, SaveDimensionObject $save): JsonResponse
    {
        $object = $save($this->user(), $this->company(), Dimension::CostCenter, [...['name' => $costCenter->name], ...$this->input($request, false)], $costCenter);

        return CursorList::one(ApiPresenter::dimensionObject($object->refresh()));
    }

    public function projects(Request $request): JsonResponse
    {
        $query = Project::query()->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')));

        return CursorList::respond($request, $query, fn (Project $object): array => ApiPresenter::dimensionObject($object));
    }

    public function project(Project $project): JsonResponse
    {
        return CursorList::one(ApiPresenter::dimensionObject($project));
    }

    public function storeProject(Request $request, SaveDimensionObject $save): JsonResponse
    {
        $object = $save($this->user(), $this->company(), Dimension::Project, $this->input($request, true));

        return CursorList::one(ApiPresenter::dimensionObject($object->refresh()), 201)
            ->header('Location', route('connect.api.projects.show', $object->id));
    }

    public function updateProject(Request $request, Project $project, SaveDimensionObject $save): JsonResponse
    {
        $object = $save($this->user(), $this->company(), Dimension::Project, [...['name' => $project->name], ...$this->input($request, false)], $project);

        return CursorList::one(ApiPresenter::dimensionObject($object->refresh()));
    }

    /** @return array<string, mixed> */
    private function input(Request $request, bool $new): array
    {
        return array_intersect_key(ApiInput::body($request->json()->all()), array_flip($new ? ['code', 'name'] : ['code', 'name', 'is_active']));
    }
}
