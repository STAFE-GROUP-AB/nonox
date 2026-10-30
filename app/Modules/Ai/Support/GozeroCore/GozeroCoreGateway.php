<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support\GozeroCore;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use LogicException;

/**
 * Sends a Laravel AI SDK prompt to GoZero Core's POST /v1/analyze: the
 * user's text as text inputs, PDFs as documents, pictures as images, the
 * agent's instructions and its structured-output schema. Small jobs run
 * synchronously; larger ones are queued at Core and polled. Core picks and
 * fails over between models itself, so nothing is retried here except a
 * pending job (docs/INTEGRATION_PROMPT.md in GoZero Core).
 */
final class GozeroCoreGateway implements StepTextGateway
{
    /** Core allows a synchronous answer for at most this many images and PDF pages. */
    private const int SYNC_VISUAL_LIMIT = 2;

    private const int POLL_SECONDS = 3;

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        if ($tools !== []) {
            throw new LogicException('GoZero Core does not run tools.');
        }

        $credentials = $provider->providerCredentials();
        $config = $provider->additionalConfiguration();
        $timeout ??= (int) ($config['timeout'] ?? 120);
        $body = $this->body($model, (string) $instructions, $messages, $schema);
        $client = Http::baseUrl(rtrim((string) ($config['url'] ?? 'https://api.gozerocore.com'), '/').'/v1')
            ->withToken((string) $credentials['key'])
            ->acceptJson()
            ->timeout($timeout);

        $response = $client->withHeaders(['Idempotency-Key' => 'nonox-'.hash('sha256', (string) json_encode($body))])->post('analyze', $body);
        $data = $this->checked($response);
        $deadline = time() + $timeout;

        while (in_array($data['status'] ?? null, ['queued', 'gating', 'running'], true)) {
            if (time() >= $deadline) {
                throw new GozeroCoreException(__('Analysen tog för lång tid. Försök igen om en stund.'), 'timeout', $data['id'] ?? null);
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
            $data = $this->checked($client->get('jobs/'.$data['id']));
        }

        if (($data['status'] ?? null) !== 'succeeded') {
            throw new GozeroCoreException(__('Analysen kunde inte göras (:status). Fyll i uppgifterna själv.', ['status' => $data['status'] ?? '?']), (string) ($data['status'] ?? ''), $data['id'] ?? null);
        }

        $receipt = app(AnalysisReceipt::class);
        $receipt->jobId = $data['id'] ?? null;
        $receipt->units = (int) ($data['meta']['units'] ?? 0);
        $receipt->confidence = isset($data['meta']['confidence']) ? (float) $data['meta']['confidence'] : null;
        $output = (array) ($data['output'] ?? []);

        return new StepResponse(
            text: (string) json_encode($output, JSON_UNESCAPED_UNICODE),
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new TextUsage,
            meta: new Meta($provider->name(), $model),
            structured: $schema === null ? null : $output,
        );
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        throw new LogicException('GoZero Core does not stream.');
    }

    /**
     * @param  array<int, mixed>  $messages
     * @param  ?array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function body(string $quality, string $instructions, array $messages, ?array $schema): array
    {
        $text = [];
        $images = [];
        $documents = [];
        $pages = 0;

        foreach ($messages as $message) {
            if (! $message instanceof UserMessage) {
                continue;
            }

            if (trim((string) $message->content) !== '') {
                $text[] = (string) $message->content;
            }

            foreach ($message->attachments as $attachment) {
                $content = $attachment->content();

                if ($attachment instanceof Image) {
                    $images[] = ['data' => base64_encode($content), 'mime' => (string) ($attachment->mimeType() ?: 'image/jpeg')];
                } elseif ($attachment instanceof Document && str_starts_with($content, '%PDF')) {
                    $documents[] = ['data' => base64_encode($content), 'mime' => 'application/pdf'];
                    $pages += max(1, preg_match_all('/\/Type\s*\/Page[^s]/', $content));
                } else {
                    throw new GozeroCoreException(__('Filen går inte att analysera. Använd PDF eller en bild i JPEG, PNG eller WebP.'), 'input.unsupported');
                }
            }
        }

        $sync = $quality === 'standard' && count($images) + $pages <= self::SYNC_VISUAL_LIMIT;

        return array_filter([
            'images' => $images,
            'text' => $text,
            'documents' => $documents,
            'instructions' => mb_substr($instructions, 0, 4000),
            'output_schema' => $schema === null ? null : (new ObjectSchema($schema))->toSchema(),
            'options' => ['mode' => $sync ? 'sync' : 'async', 'quality' => $quality, 'language' => 'sv', 'cache' => true],
        ], fn (mixed $value): bool => $value !== [] && $value !== null);
    }

    /** @return array<string, mixed> */
    private function checked(Response $response): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $error = (array) $response->json('error', []);
        $code = (string) ($error['code'] ?? $error['type'] ?? $response->status());
        $requestId = $response->header('X-Request-Id') ?: null;

        $message = match (true) {
            in_array($response->status(), [401, 403], true) => __('GoZero Core godtog inte API-nyckeln. Kontrollera nyckeln under Moduler → NONOX AI.'),
            $response->status() === 402 => __('Saldot hos GoZero Core är slut. Fyll på innan du analyserar fler underlag.'),
            $response->status() === 422 && $code === 'gate_rejected' => __('GoZero Core avböjde underlaget: :reason', ['reason' => (string) ($error['message'] ?? '')]),
            $response->status() === 429 => __('För många analyser just nu. Försök igen om en stund.'),
            str_starts_with($code, 'documents.') || str_starts_with($code, 'images.') => __('Filen går inte att analysera: :reason', ['reason' => (string) ($error['message'] ?? $code)]),
            default => __('Analysen misslyckades hos GoZero Core (:code). Fyll i uppgifterna själv eller försök igen.', ['code' => $code]),
        };

        throw new GozeroCoreException($message, $code, $requestId);
    }
}
