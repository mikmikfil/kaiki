<?php

declare(strict_types=1);

return [
    'errors' => [
        'missing_key' => 'No API key was provided. Send it as `Authorization: Bearer <key>` or in the `X-Kaiki-Key` header.',
        'malformed_key' => 'That API key is not in a valid format. A key looks like `pk_live_…` or `sk_live_…`.',
        'invalid_key' => 'That API key is not valid.',
        'revoked_key' => 'That API key has been revoked. Create a new one in your Kaiki panel.',
        'expired_key' => 'That API key has expired. Create a new one in your Kaiki panel.',
        'secret_key_in_browser' => 'A secret key was sent from a browser. Secret keys must only be used server to server — use your publishable key (`pk_…`) in anything a visitor can see.',
        'secret_key_required' => 'This endpoint needs a secret key (`sk_…`), sent as `Authorization: Bearer`. A publishable key cannot read the catalogue sync feed.',
        'invalid_updated_since' => 'The `updated_since` value is not a valid date and time. Send back the `meta.sync_cursor` from your previous run.',
        'origin_not_allowed' => 'This website is not on the allowed list for that API key. Add its address in your Kaiki panel.',
        'insufficient_scope' => 'That API key does not have permission for this action (:scope).',
        'not_found' => 'That endpoint does not exist. Check the path and the API version.',
        'method_not_allowed' => 'That endpoint does not accept this HTTP method.',
        'validation_failed' => 'Some of the values sent are not valid. See `details` for the fields.',
        'unsupported_locale' => 'That language is not supported.',
        'invalid_cursor' => 'The pagination cursor is not valid.',
        'invalid_date_range' => 'The date range is not valid.',
        'forbidden' => 'That API key is not allowed to perform this action.',
        'gone' => 'That resource is no longer available.',
        'rate_limited' => 'Too many requests. Slow down and retry after the time in `Retry-After`.',
        'enquiry_rejected' => 'That message could not be sent. If you are a person and not a script, please try again in a moment.',
        // #89 — the booking endpoints (§3.4, §4.2).
        //
        // Every one of these is read by an integrator, not a guest, and each
        // says what to do next: an error that only names the rule leaves a
        // developer reading the contract to work out the fix.
        'idempotency_key_required' => 'This request needs an `Idempotency-Key` header — a UUIDv4 you generate. It lets you retry safely without booking twice.',
        'idempotency_key_invalid' => 'The `Idempotency-Key` header must be a UUIDv4.',
        'idempotency_key_reuse' => 'That request key was already used with different data. Generate a new `Idempotency-Key` for a new request.',
        'idempotency_in_progress' => 'An identical request is still being processed. Retry in a second.',
        'booking_not_found' => 'No booking matches that reference and credential.',
        'guest_token_required' => 'This booking needs its own guest token in `X-Kaiki-Guest-Token`. A publishable key is not enough here, because the caller is claiming to be a specific guest.',
        'product_not_found' => 'That product does not exist, or is not available for booking.',
        'insufficient_capacity' => 'Those places have just been taken. Choose another departure or a smaller party.',
        'booking_not_payable' => 'This booking cannot be paid for in its current state.',
        'booking_not_cancellable' => 'This booking can no longer be cancelled online. Contact the operator.',
        'deposit_not_available' => 'This trip does not offer a deposit. Pay the full amount instead.',
        'no_balance_due' => 'There is no balance left to pay on this booking.',
        'no_gateway_configured' => 'The operator has not connected a payment provider yet. Contact them to complete this booking.',
        'lead_guest_required' => 'Fill in your details first to go on to payment.',
    ],

];
