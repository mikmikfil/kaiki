<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Live or test (data-model §2.1).
 *
 * A test key belongs to a sandbox tenant, produces bookings flagged `is_test`,
 * and talks to gateway sandbox credentials. The environment is part of the key
 * string itself (`pk_test_…`) so nobody has to look anything up to know which
 * one they are holding.
 */
enum ApiKeyEnvironment: string
{
    use HasTranslatedLabel;

    case Live = 'live';
    case Test = 'test';

    public function isTest(): bool
    {
        return $this === self::Test;
    }
}
