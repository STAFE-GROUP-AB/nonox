<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Nightly integrity check of every company's ledger (AGENTS.md §6.4).
Schedule::command('ledger:verify --all')->dailyAt('03:15')->withoutOverlapping();
