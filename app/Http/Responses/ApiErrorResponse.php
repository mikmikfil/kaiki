<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The one error envelope, per `docs/api.md` §4.1.
 *
 * ```json
 * {"error": {"code": "…", "message": "…", "message_el": "…", "details": {…}}}
 * ```
 *
 * **Both languages, in every error, always.** The guest-facing clients are a
 * Shadow-DOM widget, a Blade page and a WordPress theme, each with its own
 * locale resolution — and a booking can fail at the exact moment the widget is
 * mid-locale-switch. Shipping both strings costs a few dozen bytes and removes
 * an entire class of "the error came back in the wrong language" bug. The
 * client picks; it never renders `code` to a guest.
 *
 * This shape was hand-rolled in three middlewares before #11 (`AuthenticateApiKey`,
 * `EnsureTenantIsWritable`, `RequireApiKeyCapability`). Three copies is how the
 * fourth one comes out subtly different — a missing `message_el`, a `details`
 * that is `null` rather than absent — and each difference is a client branch
 * that breaks for one endpoint only.
 *
 * `code` is the stable contract (§4.1): clients branch on it and never on
 * `message`. `message` and `message_el` are guest-safe by construction — they
 * come from lang files, never from an exception, so a class name or a SQL
 * fragment cannot reach a tourist (CNV-11).
 */
final class ApiErrorResponse
{
    /**
     * Build the envelope from a single translation key.
     *
     * One key, both locales, because a key that exists in `en` and not in `el`
     * is caught by the I18N-3 parity gate rather than discovered by an operator.
     *
     * @param  array<string, mixed>  $replace  placeholders for the message
     * @param  array<string, mixed>  $details  machine-readable context; omitted entirely when empty
     */
    public static function fromKey(
        string $key,
        string $code,
        int $status,
        array $replace = [],
        array $details = [],
    ): JsonResponse {
        return self::make(
            code: $code,
            message: (string) __($key, $replace, 'en'),
            messageEl: (string) __($key, $replace, 'el'),
            status: $status,
            details: $details,
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(
        string $code,
        string $message,
        string $messageEl,
        int $status,
        array $details = [],
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
            'message_el' => $messageEl,
        ];

        // §4.1: "Absent rather than `null` when empty." A client that checks
        // `if (error.details)` and one that checks `if ('details' in error)`
        // must agree, and a null would split them.
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
