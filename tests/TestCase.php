<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Auth\PasskeySession;
use App\Support\Currency\EuroReferenceRates;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeEuroRates;

abstract class TestCase extends BaseTestCase
{
    private static bool $incomeTaxDataWritten = false;

    protected function setUp(): void
    {
        parent::setUp();

        // No test fetches the ECB's rates; a test sets the ones it needs (FakeEuroRates::set).
        $this->app->instance(EuroReferenceRates::class, new FakeEuroRates);

        // Work deferred until after the response (defer(), e.g. the login code mail) runs at once in tests:
        // Livewire's test requests skip the middleware that would run it. A test of the deferral calls withDefer().
        $this->withoutDefer();

        // The calculations treat the researched 2026 income tax parameters as verified, so they can be
        // tested before a human has checked them; production stays blocked until the file says verified.
        config(['nonox.income_tax_data_path' => $this->incomeTaxDataVerifiedFor(2026)]);
    }

    private function incomeTaxDataVerifiedFor(int $year): string
    {
        $directory = storage_path('framework/testing/income-tax');

        if (self::$incomeTaxDataWritten) {
            return $directory;
        }

        self::$incomeTaxDataWritten = true;

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        foreach (glob(database_path('data/income-tax/*.json')) ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            if ($data['year'] === $year) {
                $data['verified'] = true;
            }

            file_put_contents($directory.'/'.basename($file), json_encode($data, JSON_THROW_ON_ERROR));
        }

        return $directory;
    }

    /**
     * A user signed in by a test has passed every sign-in step, the passkey
     * step included (ADR-029). Tests of the passkey step itself sign in with
     * be(), which leaves the session unconfirmed.
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        return $this->withSession([PasskeySession::VERIFIED => time()]);
    }
}
