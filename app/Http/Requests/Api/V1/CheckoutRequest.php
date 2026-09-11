<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Hosted\Support\AllowedOrigin;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Http\Middleware\AuthenticateGuestToken;
use App\Models\ApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `POST /api/v1/bookings/{uuid}/checkout` (`docs/api.md` §5, schema `CheckoutRequest`).
 *
 * ## `return_url` is validated as a URL and checked against the key's origins
 *
 * The schema: *"Must be an allowed origin for the key, or a hosted-page URL."*
 * An unchecked `return_url` is an open redirect wearing a payment flow's
 * clothes — a gateway sends the guest wherever it says, from a page they
 * arrived at trusting the operator. The origin list is already on the key for
 * CORS (SEC-7), so this is the same list answering a second question rather
 * than a second list to keep in step.
 *
 * **The check was written here only in issue 111**, and the reason is worth
 * recording: until then the field was accepted and then dropped on the floor —
 * no gateway read it, so an unchecked value could not redirect anybody. The
 * sandbox checkout page is the first thing that actually sends a browser there,
 * and a validated field is a precondition of that rather than an improvement
 * on it.
 *
 * The check itself moved to {@see AllowedOrigin} on 2026-09-11, when
 * `origin_url` on `POST /bookings` became the second field asking it.
 *
 * ## `kind` is where the two-session model shows up in the wire format
 *
 * ADR-0004: a deposit and its balance are two gateway sessions, minted at
 * different times. `full`, `deposit` and `balance` are therefore a *request*
 * field rather than something derived from the booking's state — the same
 * `confirmed` booking can legitimately be asked for a balance session today
 * and could have been asked for a deposit yesterday.
 *
 * `balance` additionally requires a `manage_token` and a `confirmed` booking,
 * and that pair is enforced by {@see AuthenticateGuestToken}
 * rather than here: it is a question about the *credential*, and a form request
 * that answered it would be answering it after the booking had already been
 * loaded for a caller not entitled to it.
 */
class CheckoutRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:full,deposit,balance'],
            'gateway' => ['nullable', 'string', 'in:viva'],
            'return_url' => ['required', 'url', 'max:2000'],
            'cancel_url' => ['nullable', 'url', 'max:2000'],
        ];
    }

    /**
     * The origin check the schema promises (SEC-7).
     *
     * An empty allow-list on the key means any origin — the panel warns an
     * operator about exactly that — so this is not a second policy, it is the
     * CORS list answering a second question. A hosted page is always allowed,
     * because it is a page we serve ourselves.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $url = $this->input('return_url');

                if (! is_string($url) || $validator->errors()->has('return_url')) {
                    return;
                }

                $key = $this->attributes->get('api_key');

                if (AllowedOrigin::permits($url, $key instanceof ApiKey ? $key : null)) {
                    return;
                }

                $validator->errors()->add('return_url', (string) __('validation.custom.return_url.origin'));
            },
        ];
    }

    /** The validated destination, or null when the request did not survive. */
    public function returnUrl(): ?string
    {
        $url = $this->input('return_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function kind(): PaymentKind
    {
        return PaymentKind::from((string) $this->input('kind'));
    }

    /** Null picks the operator's default, which is what the schema says. */
    public function gateway(): ?PaymentGatewayName
    {
        $gateway = $this->input('gateway');

        return is_string($gateway) && $gateway !== ''
            ? PaymentGatewayName::from($gateway)
            : null;
    }
}
