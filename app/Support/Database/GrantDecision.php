<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * What the runtime role (nonox_app) may do with one table (ADR-079 §6).
 * TRUNCATE, REFERENCES and TRIGGER are never granted; the reader role
 * (nonox_readonly) gets SELECT on every table whatever the decision.
 */
enum GrantDecision: string
{
    /** An ordinary table the app creates, changes and deletes rows in. */
    case ReadWrite = 'read_write';

    /** Rows are created and changed, never deleted by the app (the trigger, if any, guards the changes). */
    case NoDelete = 'no_delete';

    /** Append-only: rows are only ever added. UPDATE and DELETE are refused by the grant itself. */
    case AppendOnly = 'append_only';

    /**
     * Append-only, but the app locks rows with SELECT … FOR UPDATE, which PostgreSQL allows only
     * with an UPDATE privilege. The app gets UPDATE on the id column alone: enough for the lock,
     * while an UPDATE of any other column is refused by the grant and of the id by the trigger.
     */
    case AppendOnlyLockable = 'append_only_lockable';

    /** Rows are added and, after the retention period, pruned; never changed. */
    case AppendAndPrune = 'append_and_prune';

    /** The app only reads it (the migrations table). */
    case ReadOnly = 'read_only';

    /** @return list<string> */
    public function privileges(): array
    {
        return match ($this) {
            self::ReadWrite => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            self::NoDelete => ['SELECT', 'INSERT', 'UPDATE'],
            self::AppendOnly, self::AppendOnlyLockable => ['SELECT', 'INSERT'],
            self::AppendAndPrune => ['SELECT', 'INSERT', 'DELETE'],
            self::ReadOnly => ['SELECT'],
        };
    }

    /**
     * The privileges as written in GRANT, column privileges included.
     */
    public function grant(): string
    {
        return $this === self::AppendOnlyLockable
            ? 'SELECT, INSERT, UPDATE (id)'
            : implode(', ', $this->privileges());
    }
}
