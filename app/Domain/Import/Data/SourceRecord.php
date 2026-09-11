<?php

declare(strict_types=1);

namespace App\Domain\Import\Data;

use App\Enums\ImportRowType;
use Spatie\LaravelData\Data;

/**
 * One record read from the other system, before anything decides what it
 * becomes (spec EXT-5).
 *
 * The payload is normalised by the source — a WXR product and a REST product
 * produce the same keys — so the review and commit machinery never learns which
 * of them it is looking at.
 */
final class SourceRecord extends Data
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly ImportRowType $type,
        public readonly string $sourceId,
        public readonly array $payload,
    ) {}
}
