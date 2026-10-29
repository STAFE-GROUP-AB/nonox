<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Jetstream\Membership as JetstreamMembership;

class CompanyMembership extends JetstreamMembership
{
    protected $table = 'team_user';

    /** @var bool */
    public $incrementing = true;
}
