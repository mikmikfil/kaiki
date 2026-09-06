<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Integrations\Actions\SaveIntegrationCredential;
use App\Enums\IntegrationProvider;
use RuntimeException;

/**
 * A credential set was refused before anything was written (spec PAY-4, CNV-11).
 *
 * Thrown from {@see SaveIntegrationCredential}, which is the only writer, so a
 * half-filled Stripe row cannot arrive through the panel, an import or a future
 * API by taking a different door.
 *
 * ## The message names the fields and never their values
 *
 * The distinction this class exists for. An exception is the single most likely
 * thing to be logged, reported to Sentry, or pasted into an issue — SEC-9 and
 * MYD-15 both say a credential is never logged, and an exception carrying
 * `secret_key: sk_live_…` in its message would breach that from inside the
 * error path, which is where nobody is looking. So the message is built from
 * field *names*, and `NoCredentialLeakTest` asserts a thrown instance's message
 * and context contain no value.
 *
 * The sentence comes from `lang/*\/integrations.php`, never from a literal here.
 */
final class IntegrationCredentialIncomplete extends RuntimeException
{
    /**
     * @param  list<string>  $fields  field names, never values
     */
    private function __construct(string $message, public readonly IntegrationProvider $provider, public readonly array $fields)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $fields
     */
    public static function missing(IntegrationProvider $provider, array $fields): self
    {
        $names = array_map(
            static fn (string $field): string => (string) trans('integrations.fields.' . $field),
            $fields,
        );

        return new self(
            (string) trans('integrations.refused.incomplete', [
                'provider' => $provider->label(),
                'fields' => implode(', ', $names),
            ]),
            $provider,
            $fields,
        );
    }
}
