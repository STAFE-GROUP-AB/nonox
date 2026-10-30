<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;

/**
 * The AI providers a company can use with its own API key, through the
 * Laravel AI SDK's built-in drivers. Which model is cheapest or smartest at
 * each provider is the SDK's knowledge and follows SDK updates. A consumer subscription (ChatGPT Plus,
 * Claude Pro, Gemini Advanced) cannot be used by another application; an API
 * key is the only way, so each provider says where to create one.
 */
enum AiProvider: string
{
    case OpenAi = 'OPENAI';
    case Anthropic = 'ANTHROPIC';
    case Gemini = 'GEMINI';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI (ChatGPT)',
            self::Anthropic => 'Anthropic (Claude)',
            self::Gemini => 'Google (Gemini)',
        };
    }

    /** The Laravel AI SDK driver. */
    public function driver(): string
    {
        return match ($this) {
            self::OpenAi => 'openai',
            self::Anthropic => 'anthropic',
            self::Gemini => 'gemini',
        };
    }

    /**
     * The provider's cheapest and smartest text models as the installed
     * Laravel AI SDK knows them; no request is made.
     *
     * @return array{CHEAPEST: string, SMARTEST: string}
     */
    public function models(): array
    {
        $provider = Ai::build(['name' => 'nonox_ai_models_'.strtolower($this->value), 'driver' => $this->driver(), 'key' => 'unused']);

        return $provider instanceof TextProvider
            ? ['CHEAPEST' => $provider->cheapestTextModel(), 'SMARTEST' => $provider->smartestTextModel()]
            : ['CHEAPEST' => '', 'SMARTEST' => ''];
    }

    /** Where the customer creates an API key. */
    public function keyUrl(): string
    {
        return match ($this) {
            self::OpenAi => 'https://platform.openai.com/api-keys',
            self::Anthropic => 'https://console.anthropic.com/settings/keys',
            self::Gemini => 'https://aistudio.google.com/apikey',
        };
    }

    public function keyPattern(): string
    {
        return match ($this) {
            self::OpenAi => '/^sk-[A-Za-z0-9_\-]{20,}$/',
            self::Anthropic => '/^sk-ant-[A-Za-z0-9_\-]{20,}$/',
            self::Gemini => '/^AIza[A-Za-z0-9_\-]{30,}$/',
        };
    }

    /**
     * Asks the provider for its list of models, which costs nothing, to learn
     * whether the key works. True when it does, false when the provider refuses
     * it, null when the provider cannot be reached.
     */
    public function accepts(string $key): ?bool
    {
        try {
            $response = match ($this) {
                self::OpenAi => Http::withToken($key)->timeout(10)->get('https://api.openai.com/v1/models'),
                self::Anthropic => Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(10)->get('https://api.anthropic.com/v1/models'),
                self::Gemini => Http::withHeaders(['x-goog-api-key' => $key])->timeout(10)->get('https://generativelanguage.googleapis.com/v1beta/models'),
            };
        } catch (ConnectionException) {
            return null;
        }

        if (in_array($response->status(), [400, 401, 403], true)) {
            return false;
        }

        return $response->successful() ? true : null;
    }
}
