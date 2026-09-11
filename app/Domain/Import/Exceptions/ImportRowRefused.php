<?php

declare(strict_types=1);

namespace App\Domain\Import\Exceptions;

use RuntimeException;

/**
 * One row the committer will not write, with the reason as a lang key and its
 * parameters, so the row's log reads in the operator's own language.
 */
final class ImportRowRefused extends RuntimeException
{
    /** @param array<string, scalar|null> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
