<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Http\Middleware\AuthenticateGuestToken;
use Illuminate\Foundation\Http\FormRequest;

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
            'gateway' => ['nullable', 'string', 'in:viva,stripe'],
            'return_url' => ['required', 'url', 'max:2000'],
            'cancel_url' => ['nullable', 'url', 'max:2000'],
        ];
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
