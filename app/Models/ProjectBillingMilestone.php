<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\MilestoneStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One milestone of a project's billing plan (ADR-081 gap 6, E8): a date and
 * either an amount excluding VAT or a share of the contract sum. "Fakturera"
 * turns it into a draft invoice with the project on its row; deleting that
 * draft frees the milestone again (the foreign key sets invoice_id to null).
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property int $position
 * @property string $description
 * @property ?CarbonImmutable $due_on
 * @property ?Money $amount_ore
 * @property ?int $share_bp
 * @property MilestoneStatus $status
 * @property ?string $invoice_id
 * @property ?int $created_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class ProjectBillingMilestone extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'project_id', 'position', 'description', 'due_on', 'amount_ore', 'share_bp', 'status', 'invoice_id', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'PLANNED'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'due_on' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
            'share_bp' => 'integer',
            'status' => MilestoneStatus::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The status as it stands: a milestone whose draft invoice was deleted is
     * ready to invoice again.
     */
    public function effectiveStatus(): MilestoneStatus
    {
        return $this->status === MilestoneStatus::Invoiced && $this->invoice_id === null ? MilestoneStatus::Ready : $this->status;
    }

    /**
     * The amount excluding VAT in öre: the amount, or the share of the
     * contract sum rounded half up; null when a share has no contract sum.
     */
    public function amountFor(?int $contractOre): ?int
    {
        if ($this->amount_ore !== null) {
            return $this->amount_ore->ore;
        }

        if ($contractOre === null || $this->share_bp === null) {
            return null;
        }

        return intdiv($contractOre * $this->share_bp + 5_000, 10_000);
    }

    /** What the person saw, to bind "Fakturera" to that version (ADR-081 wave C). */
    public function versionToken(): string
    {
        return hash('sha256', implode('|', [
            $this->id,
            $this->description,
            $this->due_on?->toDateString() ?? '',
            $this->amount_ore === null ? '' : $this->amount_ore->ore,
            $this->share_bp ?? '',
            $this->status->value,
            $this->invoice_id ?? '',
            $this->updated_at?->format('Y-m-d H:i:s.uP') ?? '',
        ]));
    }
}
