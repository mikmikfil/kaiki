<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns an exception thrown under `/api/v1` into the §4.1 envelope.
 *
 * Only the exceptions that reach the client *instead of* a controller are
 * mapped here. Anything a controller throws deliberately builds its own
 * envelope with a `code` that means something specific — mapping those
 * centrally would flatten every domain failure into one generic code, and §4.1
 * is explicit that `code` is the stable contract clients branch on.
 *
 * **Nothing from the exception reaches the response.** §4.1: messages "never
 * contain a class name, a SQL fragment, a stack frame or an internal id".
 * Every string here comes from a lang file, and the 500 case deliberately says
 * nothing at all beyond a request id — the real exception goes to the log with
 * the tenant tag, never to a tourist's browser.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => self::validation($e),
            $e instanceof NotFoundHttpException => ApiErrorResponse::fromKey(
                'api.errors.not_found', 'not_found', 404,
            ),
            $e instanceof MethodNotAllowedHttpException => ApiErrorResponse::fromKey(
                'api.errors.method_not_allowed', 'method_not_allowed', 405,
            ),
            $e instanceof AuthenticationException => ApiErrorResponse::fromKey(
                'api.errors.missing_key', 'missing_key', 401,
            ),
            $e instanceof AuthorizationException => ApiErrorResponse::fromKey(
                'api.errors.forbidden', 'forbidden', 403,
            ),
            // A `JsonResponse` thrown as an HTTP exception by our own
            // middleware already carries the envelope; leave it alone.
            $e instanceof HttpExceptionInterface => self::httpException($e),
            default => null,
        };
    }

    /**
     * §4.1: "Validation errors put the per-field detail in `details` keyed by
     * request field path, each with its own bilingual pair."
     *
     * A 422 that does not say which field is a support ticket rather than an
     * error message.
     */
    private static function validation(ValidationException $e): JsonResponse
    {
        $details = [];

        foreach ($e->errors() as $field => $messages) {
            $details[$field] = array_values(array_filter($messages, 'is_string'));
        }

        return ApiErrorResponse::fromKey(
            key: 'api.errors.validation_failed',
            code: 'validation_failed',
            status: 422,
            details: $details,
        );
    }

    /**
     * Anything else with an HTTP status — 429 from the rate limiter (#35), a
     * deliberate `abort()` — gets a generic envelope for its status class
     * rather than leaking the abort message, which is often a developer note.
     */
    private static function httpException(HttpExceptionInterface $e): ?JsonResponse
    {
        $status = $e->getStatusCode();

        $code = match ($status) {
            403 => 'forbidden',
            410 => 'gone',
            429 => 'rate_limited',
            default => null,
        };

        if ($code === null) {
            // 5xx and anything unmapped fall through to Laravel, which reports
            // to the log. Inventing a code here would make an unknown failure
            // look like a documented one.
            return null;
        }

        return ApiErrorResponse::fromKey("api.errors.{$code}", $code, $status);
    }
}
