<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ConcreteValidator;
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
    /**
     * `422 validation_failed`, in the shape §4.2 fixes.
     *
     * ```json
     * "details": { "fields": { "pax.0.qty": { "code": "min", "message": …, "message_el": … } } }
     * ```
     *
     * **Three things about it are easy to get wrong, and #35 got two of them.**
     *
     * 1. The map is nested under `fields`, not spread across `details`. `details`
     *    carries other keys on other codes — `retry_after_seconds`, `max_days`
     *    — so a client that reads `details` as "the field errors" breaks the
     *    first time it meets one of those.
     * 2. Each entry is an **object**, not a list of strings. `code` is what a
     *    client branches on: it is the failing rule, so a form can highlight a
     *    field and pick its own wording without parsing English.
     * 3. `message_el` is per field. §4.1 promises both languages in *every*
     *    error, and a top-level pair over English-only field messages keeps the
     *    promise at the envelope and breaks it where the guest actually reads.
     *
     * The Greek is obtained by re-running the same validator under `el` rather
     * than by translating the produced string, because a message is assembled
     * from a lang line and its `:attribute` substitutions — there is nothing to
     * translate after the fact.
     */
    private static function validation(ValidationException $e): JsonResponse
    {
        $greek = self::messagesIn($e, 'el');
        $english = self::messagesIn($e, 'en');
        $failed = $e->validator instanceof ConcreteValidator ? $e->validator->failed() : [];

        $fields = [];

        foreach ($e->errors() as $field => $messages) {
            $rules = array_keys((array) ($failed[$field] ?? []));

            $fields[$field] = [
                // The first failing rule, snake_cased. Laravel reports them
                // StudlyCase (`RequiredWith`), and every other machine value in
                // this API is snake — a client should not have to learn a
                // second convention for one field.
                'code' => $rules === [] ? 'invalid' : Str::snake((string) $rules[0]),
                'message' => $english[$field][0] ?? ($messages[0] ?? ''),
                'message_el' => $greek[$field][0] ?? ($messages[0] ?? ''),
            ];
        }

        return ApiErrorResponse::fromKey(
            key: 'api.errors.validation_failed',
            code: 'validation_failed',
            status: 422,
            details: $fields === [] ? [] : ['fields' => $fields],
        );
    }

    /**
     * The same failures, worded in one named locale.
     *
     * Rebuilt from the validator's own data, rules and custom messages so that
     * a `messages()` override on a FormRequest is honoured in both languages —
     * the alternative is a Greek envelope carrying an English custom message,
     * which is the exact half-translated failure §4.1 exists to prevent.
     *
     * @return array<string, list<string>>
     */
    private static function messagesIn(ValidationException $e, string $locale): array
    {
        $source = $e->validator;

        // The contract interface exposes neither the data nor the rules, and
        // only the concrete validator carries the custom messages a FormRequest
        // may have overridden. Anything else is somebody's own implementation,
        // and the honest answer there is the messages it already produced.
        if (! $source instanceof ConcreteValidator) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return $errors;
        }

        $original = App::getLocale();

        try {
            App::setLocale($locale);

            $validator = ValidatorFacade::make(
                $source->getData(),
                $source->getRules(),
                $source->customMessages,
                $source->customAttributes,
            );

            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return $errors;
        } finally {
            App::setLocale($original);
        }
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
