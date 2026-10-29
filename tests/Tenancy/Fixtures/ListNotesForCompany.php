<?php

declare(strict_types=1);

namespace Tests\Tenancy\Fixtures;

use App\Support\Tenancy\RunsForCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A queued job that reads company data, used to prove that jobs only ever see
 * the company they were dispatched for.
 */
class ListNotesForCompany implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use RunsForCompany;

    /** @var list<int> */
    public static array $seenCompanyIds = [];

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(public int $companyId, public string $model) {}

    public function handle(): void
    {
        $ids = ($this->model)::query()->pluck('company_id')->unique()->all();

        static::$seenCompanyIds = array_values(array_map(intval(...), $ids));
    }
}
