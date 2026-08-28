<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Every tenant must keep at least one owner (data-model §2.1).
 *
 * Enforced in the application rather than the database: no portable constraint
 * expresses "at least one row matching this predicate", and inventing one with
 * triggers would not survive the SQLite/MySQL split.
 *
 * The message reaches an operator, so it exists in Greek and English.
 */
final class LastOwnerException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('tenancy.last_owner'));
    }
}
