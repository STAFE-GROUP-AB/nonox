<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support\GozeroCore;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

/**
 * GoZero Core as a Laravel AI SDK text provider (driver "gozerocore"). Core
 * chooses the model itself; the "model" here is Core's quality level,
 * "standard" or "high".
 */
class GozeroCoreProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    /** @param  array<string, mixed>  $config */
    public function __construct(protected array $config, protected Dispatcher $events) {}

    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new GozeroCoreGateway;
    }

    public function defaultTextModel(): string
    {
        return (string) ($this->config['quality'] ?? 'standard');
    }

    public function cheapestTextModel(): string
    {
        return 'standard';
    }

    public function smartestTextModel(): string
    {
        return 'high';
    }
}
