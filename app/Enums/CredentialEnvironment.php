<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Which set of an operator's credentials a call should use (data-model §2.7).
 *
 * ## `test`, not ADR-0004's `sandbox`
 *
 * ADR-0004 Option A wrote `mode: live | sandbox`. `api_keys.environment` was
 * already `live | test` ({@see ApiKeyEnvironment}), and two words for one
 * concept across two tables is how a query eventually asks the wrong one —
 * PAY-11 pairs them directly, since sandbox mode means a `pk_test_` key
 * reaching test gateway credentials. The reconciliation went to `docs/spec.md`
 * PAY-4 with its reason in `CHANGELOG.md`; the *decision* in ADR-0004 is
 * untouched. "Sandbox mode" survives as the operator-facing name of the mode,
 * which is prose and not a column value.
 *
 * ## Why this is not simply `ApiKeyEnvironment`
 *
 * Because they answer different questions — one is a property of a credential
 * the operator gave us, the other of a key we issued them — and a shared enum
 * would mean renaming a column's cast to widen an unrelated concept. They must
 * nevertheless never disagree about their vocabulary, so
 * `CredentialEnvironmentTest` asserts the two case lists are identical. The day
 * one grows a case the other has not, PAY-11 has a hole in it and the suite
 * says so rather than a checkout quietly choosing the wrong keys.
 */
enum CredentialEnvironment: string
{
    use HasTranslatedLabel;

    case Live = 'live';
    case Test = 'test';

    public function isTest(): bool
    {
        return $this === self::Test;
    }

    /** The credential environment a key of the given environment must reach (PAY-11). */
    public static function forApiKey(ApiKeyEnvironment $environment): self
    {
        return $environment->isTest() ? self::Test : self::Live;
    }
}
