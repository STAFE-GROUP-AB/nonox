<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The PDF engine's health as /hq and the signing screens see it. Starting
 * the helper costs a fraction of a second, so the answer is cached for ten
 * minutes; `signing:engine-doctor` refreshes it. An installation without the
 * engine never starts a process: {@see usable()} answers from
 * {@see PdfEngine::isInstalled()} first.
 */
final class PdfEngineStatus
{
    public const string CACHE_KEY = 'signing:pdf-engine:health';

    public const int CACHE_SECONDS = 600;

    /** The last answer, kept longer, for requests that must not wait for a check in progress. */
    public const string LAST_KEY = 'signing:pdf-engine:health:last';

    public const int LAST_SECONDS = 86400;

    public const string LOCK_KEY = 'signing:pdf-engine:health:lock';

    /** Longer than a health check (30 s) and shorter than a self-test may take, so a crashed check frees it. */
    public const int LOCK_SECONDS = 120;

    public function __construct(private readonly PdfEngine $engine) {}

    public function engine(): PdfEngine
    {
        return $this->engine;
    }

    /**
     * The cached health. When nothing fresh is cached, one request checks
     * (behind a lock) while every other request answers at once with the
     * last known value, or "inte kontrollerad", and never waits.
     */
    public function current(): PdfEngineHealth
    {
        if (! $this->engine->isInstalled()) {
            return $this->engine->health();
        }

        $fresh = $this->cached(self::CACHE_KEY);

        if ($fresh !== null) {
            return $fresh;
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->cached(self::LAST_KEY) ?? $this->notChecked();
        }

        try {
            return $this->check(false);
        } finally {
            $lock->release();
        }
    }

    /**
     * Checks now (with the self-test when asked) and caches the answer; for
     * signing:engine-doctor. Waits a short while for a check that is already
     * running, and checks anyway if it does not finish.
     */
    public function refresh(bool $selfTest = false): PdfEngineHealth
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return $this->check($selfTest);
        }

        try {
            return $this->check($selfTest);
        } finally {
            $lock->release();
        }
    }

    private function check(bool $selfTest): PdfEngineHealth
    {
        $health = $this->engine->health($selfTest);

        if ($this->engine->isInstalled()) {
            Cache::put(self::CACHE_KEY, $health->toArray(), self::CACHE_SECONDS);
            Cache::put(self::LAST_KEY, $health->toArray(), self::LAST_SECONDS);
        }

        return $health;
    }

    private function cached(string $key): ?PdfEngineHealth
    {
        $cached = Cache::get($key);

        return is_array($cached) && ($cached['driver'] ?? null) === $this->engine->driver()
            ? PdfEngineHealth::fromArray($cached)
            : null;
    }

    private function notChecked(): PdfEngineHealth
    {
        return new PdfEngineHealth(
            driver: $this->engine->driver(),
            installed: true,
            working: false,
            problems: [__('PDF-motorn är inte kontrollerad ännu. Den kontrolleras just nu; ladda om sidan om en stund.')],
        );
    }

    /** Whether the screens that need the engine (fields in the PDF, the seal) may be offered. */
    public function usable(): bool
    {
        return $this->engine->isInstalled() && $this->current()->usable();
    }

    /** Where a self-hoster reads how to install the engine. */
    public static function guideUrl(): string
    {
        return route('legal.selfHosting');
    }
}
