<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\SaveSegment;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Domain\MailingAudience;
use App\Modules\Crm\Domain\MarketingExclusion;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Domain\SegmentFilter;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Segment;
use App\Modules\Crm\Support\CompanyMembers;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Segment (ADR-048): saved filters over the CRM's people, for mailings.
 * While a segment is edited the page shows how many people it holds and
 * how many of them a mailing could reach, with the reasons for the rest.
 * crm:settings writes; crm:mailings may look.
 */
class SegmentsPage extends Component
{
    use ActsInCompany;
    use ShowsToasts;

    public ?int $editingId = null;

    public bool $open = false;

    public string $name = '';

    /** @var array<string, mixed> */
    public array $filters = [];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:settings') || $this->user()->hasTeamPermission($this->company(), 'crm:mailings'), 403);
    }

    public function start(?int $id = null): void
    {
        $this->authorizeSettings();
        $segment = $id === null ? null : Segment::query()->findOrFail($id);
        $this->editingId = $segment?->id;
        $this->name = $segment->name ?? '';
        $filters = SegmentFilter::from($segment->filters ?? [])->toArray();
        $filters['tags'] = implode(', ', $filters['tags']);
        $filters['owner_id'] = (string) ($filters['owner_id'] ?? '');
        $this->filters = $filters;
        $this->open = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'open', 'name', 'filters');
        $this->resetErrorBag();
    }

    public function save(SaveSegment $save): void
    {
        $this->authorizeSettings();
        $segment = $save($this->user(), $this->company(), ['name' => $this->name, 'filters' => $this->normalised()], $this->editingId === null ? null : Segment::query()->findOrFail($this->editingId));
        $this->cancel();
        $this->toast(__('Segmentet :name är sparat.', ['name' => $segment->name]));
    }

    public function delete(int $id, SaveSegment $save): void
    {
        $this->authorizeSettings();
        $save->delete($this->user(), $this->company(), Segment::query()->findOrFail($id));
        $this->cancel();
        $this->toast(__('Segmentet är borttaget.'));
    }

    public function render(): View
    {
        $preview = null;

        if ($this->open) {
            $preview = MailingAudience::forFilter(SegmentFilter::from($this->normalised()), false);
        }

        return view('crm::livewire.segments', [
            'segments' => Segment::query()->orderBy('name')->get(),
            'canSettings' => $this->user()->hasTeamPermission($this->company(), 'crm:settings'),
            'preview' => $preview,
            'statuses' => AccountStatus::cases(),
            'kinds' => AccountKind::cases(),
            'roles' => PersonRole::cases(),
            'bases' => LegalBasis::cases(),
            'members' => CompanyMembers::all($this->company()),
            'tags' => $this->open ? Account::query()->toBase()->selectRaw('DISTINCT jsonb_array_elements_text(tags) AS tag')->orderBy('tag')->limit(200)->pluck('tag') : collect(),
            'exclusions' => MarketingExclusion::cases(),
        ]);
    }

    /** @return array<string, mixed> */
    private function normalised(): array
    {
        $filters = $this->filters;
        $filters['tags'] = array_values(array_filter(array_map('trim', explode(',', (string) ($filters['tags'] ?? '')))));
        $filters['owner_id'] = blank($filters['owner_id'] ?? null) ? null : (int) $filters['owner_id'];

        return $filters;
    }

    private function authorizeSettings(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:settings'), 403);
    }
}
