<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/*
 * Every test runs against the dedicated PostgreSQL test database inside a
 * transaction that is rolled back afterwards. Never rebuild the schema per test.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature', 'Unit', 'Tenancy');
