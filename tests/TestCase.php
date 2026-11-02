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
    protected function setUp(): void
    {
        parent::setUp();

        // No test fetches the ECB's rates; a test sets the ones it needs (FakeEuroRates::set).
        $this->app->instance(EuroReferenceRates::class, new FakeEuroRates);
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
