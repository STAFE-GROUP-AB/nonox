<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Hq\ExcludeFromRegistry;
use App\Models\RegistryCompany;
use App\Models\RegistryExclusion;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * An objection or erasure request about the company register from the
 * console (ADR-047), the same as HQ → Företagsregistret:
 *
 *   php artisan registry:exclude 559999-6013 --field=PHONE --reason=OBJECTION
 *   php artisan registry:exclude 559999-6013 --field=ALL --remove
 */
class RegistryExcludeCommand extends Command
{
    protected $signature = 'registry:exclude
        {org_number : Organisationsnumret}
        {--field=ALL : ALL (hela företaget), NAME, PHONE eller EMAIL}
        {--reason=OBJECTION : OBJECTION (invändning) eller ERASURE (radering)}
        {--remove : Ta bort undantaget i stället}';

    protected $description = 'Undantar uppgifter i företagsregistret efter en invändning eller begäran om radering.';

    public function handle(ExcludeFromRegistry $exclude): int
    {
        $field = strtoupper((string) $this->option('field'));

        if ($this->option('remove')) {
            $exclusion = RegistryExclusion::query()->where('org_number', RegistryCompany::digits((string) $this->argument('org_number')) ?? '')->where('field', $field)->first();

            if ($exclusion === null) {
                $this->error('Det finns inget sådant undantag.');

                return self::FAILURE;
            }

            $exclude->remove(null, $exclusion);
            $this->comment('Undantaget är borttaget. Uppgifterna kommer tillbaka vid nästa inläsning.');

            return self::SUCCESS;
        }

        try {
            $exclusion = $exclude(null, ['org_number' => (string) $this->argument('org_number'), 'field' => $field, 'reason' => strtoupper((string) $this->option('reason'))]);
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        }

        $this->comment("Undantag {$exclusion->id} ({$exclusion->field}) är sparat. Uppgifterna är borta ur registret och läses inte in igen.");

        return self::SUCCESS;
    }
}
