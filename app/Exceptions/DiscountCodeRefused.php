<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Pricing\Actions\ApplyDiscountCode;
use RuntimeException;

/**
 * A discount code that cannot be used here and now (2026-09-17).
 *
 * The message is the sentence the guest reads, already in their language —
 * {@see ApplyDiscountCode::refusal()} chose it. The hosted checkout shows it
 * under the field; the API answers `422 invalid_discount_code` with it.
 */
final class DiscountCodeRefused extends RuntimeException {}
