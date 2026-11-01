<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Auth\PasskeySession;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
