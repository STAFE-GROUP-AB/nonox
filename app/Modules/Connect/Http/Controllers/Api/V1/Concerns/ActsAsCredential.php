<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\ConnectCredential;

/**
 * The person and company behind the request's credential (ADR-032). The
 * Actions are always called as that person, so every Policy applies the
 * intersection of the credential's scopes and the person's own rights.
 */
trait ActsAsCredential
{
    private function credential(): ConnectCredential
    {
        return ConnectAccess::current() ?? abort(401);
    }

    private function user(): User
    {
        return $this->credential()->user;
    }

    private function company(): Company
    {
        return $this->credential()->company;
    }
}
