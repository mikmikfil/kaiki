<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Data\TranslatableMessage;
use App\Enums\PaymentGatewayName;

/**
 * Gateway error codes, per gateway, in four sentences each (spec PAY-12).
 *
 * ## Why per gateway rather than one shared table
 *
 * Because the two do not agree on what an error *is*. Stripe's
 * `card_declined` carries a `decline_code` that says why; Viva returns a
 * numeric event code where a single number covers cases Stripe splits in three.
 * Normalising them into a shared vocabulary would mean inventing a third
 * taxonomy that matches neither, and then maintaining the mapping in two
 * directions.
 *
 * So each gateway owns its own dictionary and both produce the same
 * {@see TranslatableMessage}. The *shape* is shared; the codes are not.
 *
 * ## The dictionary maps to lang keys, never to sentences
 *
 * CNV-11: an error message that reaches an operator comes from a lang file. A
 * dictionary holding Greek strings would be a lang file in the wrong place,
 * invisible to the EL/EN parity gate and to the hardcoded-string scanner.
 *
 * ## An unmapped code is a designed outcome, not a gap
 *
 * Neither gateway publishes a complete, stable list, and both add codes without
 * telling anybody. So the question is not *whether* an unmapped code arrives
 * but what happens when it does: the guest gets the generic sentence, and the
 * operator gets the raw code marked unrecognised. Never nothing, and never the
 * raw code to the guest.
 */
final class GatewayErrorDictionary
{
    /**
     * Stripe's codes, mapped to the pair of lang keys each one deserves.
     *
     * Chosen for what an operator can *do* about them. `card_declined` and
     * `insufficient_funds` both mean "try another card" to a guest and mean
     * very different things to an operator deciding whether to chase a balance.
     *
     * @var array<array-key, array{guest: string, operator: string}>
     */
    private const STRIPE = [
        'card_declined' => ['guest' => 'payments.guest.declined', 'operator' => 'payments.operator.stripe.card_declined'],
        'insufficient_funds' => ['guest' => 'payments.guest.declined', 'operator' => 'payments.operator.stripe.insufficient_funds'],
        'expired_card' => ['guest' => 'payments.guest.expired_card', 'operator' => 'payments.operator.stripe.expired_card'],
        'incorrect_cvc' => ['guest' => 'payments.guest.card_details', 'operator' => 'payments.operator.stripe.incorrect_cvc'],
        'processing_error' => ['guest' => 'payments.guest.temporary', 'operator' => 'payments.operator.stripe.processing_error'],
        'authentication_required' => ['guest' => 'payments.guest.authentication', 'operator' => 'payments.operator.stripe.authentication_required'],
        'session_expired' => ['guest' => 'payments.guest.session_expired', 'operator' => 'payments.operator.stripe.session_expired'],
        // Not the guest's fault and not their problem: the operator's own keys
        // are wrong, and the guest must not be told their card failed.
        'api_key_expired' => ['guest' => 'payments.guest.temporary', 'operator' => 'payments.operator.stripe.api_key_expired'],
        'amount_too_small' => ['guest' => 'payments.guest.temporary', 'operator' => 'payments.operator.stripe.amount_too_small'],
        'charge_already_refunded' => ['guest' => 'payments.guest.generic', 'operator' => 'payments.operator.stripe.charge_already_refunded'],
    ];

    /**
     * Viva's codes.
     *
     * Numeric, and written as the strings Viva sends — though PHP stores them
     * as integers regardless, because it coerces numeric array keys. That is
     * why {@see self::codesFor()} casts them back and why the lookup below
     * works either way: an error code is an identifier that happens to look
     * like a number.
     *
     * `array-key` rather than `string`, for the reason above: PHP has already
     * turned these into integers by the time anything can look at them.
     *
     * @var array<array-key, array{guest: string, operator: string}>
     */
    private const VIVA = [
        '0' => ['guest' => 'payments.guest.generic', 'operator' => 'payments.operator.viva.success_with_error'],
        '2' => ['guest' => 'payments.guest.declined', 'operator' => 'payments.operator.viva.declined'],
        '3' => ['guest' => 'payments.guest.card_details', 'operator' => 'payments.operator.viva.invalid_card'],
        '4' => ['guest' => 'payments.guest.expired_card', 'operator' => 'payments.operator.viva.expired_card'],
        '5' => ['guest' => 'payments.guest.temporary', 'operator' => 'payments.operator.viva.issuer_unavailable'],
        '6' => ['guest' => 'payments.guest.session_expired', 'operator' => 'payments.operator.viva.order_expired'],
        // Viva's own authentication failure — the operator's credentials, not
        // the guest's card.
        '401' => ['guest' => 'payments.guest.temporary', 'operator' => 'payments.operator.viva.unauthorised'],
        '404' => ['guest' => 'payments.guest.generic', 'operator' => 'payments.operator.viva.order_not_found'],
    ];

    /**
     * Every code this dictionary knows, for the parity test.
     *
     * **Cast back to string**, and the cast is not decoration. PHP coerces a
     * numeric-looking array key to an `int` whether you want it to or not, so
     * `'2' => [...]` is stored as `2` and `array_keys()` hands back integers —
     * which then fail `describe()`'s `string` parameter. Viva's codes are the
     * only ones affected, because Stripe's are words.
     *
     * An error code is an identifier that happens to look like a number, and
     * the alternative to this cast is a `string|int` signature spreading
     * outward from here through the dictionary, the message and the exception.
     *
     * @return list<string>
     */
    public static function codesFor(PaymentGatewayName $gateway): array
    {
        return array_map('strval', array_keys(self::tableFor($gateway)));
    }

    /**
     * The lang keys a code maps to, or null when nothing does.
     *
     * @return array{guest: string, operator: string}|null
     */
    public static function keysFor(PaymentGatewayName $gateway, string $code): ?array
    {
        return self::tableFor($gateway)[$code] ?? null;
    }

    /**
     * The four sentences for a code, mapped or not.
     *
     * The single entry point, so that no caller can accidentally implement the
     * unmapped fallback differently — which is where "the guest saw a raw
     * Stripe code" comes from.
     */
    public static function describe(PaymentGatewayName $gateway, string $code): TranslatableMessage
    {
        $keys = self::keysFor($gateway, $code);

        if ($keys === null) {
            return TranslatableMessage::unmapped($code);
        }

        return TranslatableMessage::mapped($keys['guest'], $keys['operator'], $code);
    }

    /** @return array<array-key, array{guest: string, operator: string}> */
    private static function tableFor(PaymentGatewayName $gateway): array
    {
        return match ($gateway) {
            PaymentGatewayName::Stripe => self::STRIPE,
            PaymentGatewayName::Viva => self::VIVA,
            // Cash and bank transfer have no gateway to return an error. An
            // empty table means every code is unmapped, which is the honest
            // answer rather than a fabricated dictionary.
            default => [],
        };
    }
}
