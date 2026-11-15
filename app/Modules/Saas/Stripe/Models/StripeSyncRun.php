<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One sync of a Stripe connection, for the sync log (ADR-080 step 2): what
 * kind, when, how many requests, how many objects and versions, and an error
 * code when it stopped. Never a key, a message from Stripe or customer data.
 *
 * @property int $id
 * @property int $company_id
 * @property int $connection_id
 * @property string $kind
 * @property string $status
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property int $requests
 * @property ?array<string, int> $counts
 * @property ?string $error
 */
class StripeSyncRun extends Model
{
    use BelongsToCompany;

    public const string INITIAL = 'INITIAL';

    public const string INCREMENTAL = 'INCREMENTAL';

    /** A full re-read, when the last event seen is older than Stripe keeps events (30 days). */
    public const string FULL = 'FULL';

    public const string RUNNING = 'RUNNING';

    public const string OK = 'OK';

    public const string FAILED = 'FAILED';

    /** Stripe is busy or down; the run stopped and the next one goes on from where it was. */
    public const string INTERRUPTED = 'INTERRUPTED';

    protected $table = 'stripe_sync_runs';

    /** @var list<string> */
    protected $fillable = ['company_id', 'connection_id', 'kind', 'status', 'started_at', 'finished_at', 'requests', 'counts', 'error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'requests' => 'integer',
            'counts' => 'array',
        ];
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::INITIAL => __('Första inläsningen'),
            self::FULL => __('Hela kontot läst igen'),
            default => __('Ändringar sedan sist'),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OK => __('Klar'),
            self::FAILED => __('Misslyckades'),
            self::INTERRUPTED => __('Avbruten, fortsätter nästa gång'),
            default => __('Pågår'),
        };
    }

    public function errorLabel(): ?string
    {
        if ($this->error === null) {
            return null;
        }

        return match ($this->error) {
            'unauthorized' => __('Stripe godtar inte nyckeln längre. Kopplingen är avslutad.'),
            'forbidden' => __('Nyckeln saknar en läsbehörighet som NONOX behöver.'),
            'rate_limited' => __('Stripe bad NONOX vänta. Inläsningen fortsätter nästa gång.'),
            'unavailable' => __('Stripe svarade inte. Inläsningen fortsätter nästa gång.'),
            default => __('Okänt fel (:code).', ['code' => $this->error]),
        };
    }
}
