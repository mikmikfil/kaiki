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
 * Because no two gateways agree on what an error *is*. Viva returns a numeric
 * event code where one number covers cases another provider would split in
 * three. Normalising them into a shared vocabulary would mean inventing a
 * third taxonomy that matches neither, and then maintaining the mapping in
 * two directions.
 *
 * So each gateway owns its own dictionary and they all produce the same
 * {@see TranslatableMessage}. The *shape* is shared; the codes are not — which
 * is what makes a second gateway a table rather than a rewrite.
 *
 * ## The dictionary maps to lang keys, never to sentences
 *
 * CNV-11: an error message that reaches an operator comes from a lang file. A
 * dictionary holding Greek strings would be a lang file in the wrong place,
 * invisible to the EL/EN parity gate and to the hardcoded-string scanner.
 *
 * ## An unmapped code is a designed outcome, not a gap
 *
 * No gateway publishes a complete, stable list, and they add codes without
 * telling anybody. So the question is not *whether* an unmapped code arrives
 * but what happens when it does: the guest gets the generic sentence, and the
 * operator gets the raw code marked unrecognised. Never nothing, and never the
 * raw code to the guest.
 */
final class GatewayErrorDictionary
{
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
     * which then fail `describe()`'s `string` parameter. Only numeric codes are
     * affected; a gateway whose codes are words never hits this.
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
     * gateway code" comes from.
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
            PaymentGatewayName::Viva => self::VIVA,
            // Cash and bank transfer have no gateway to return an error. An
            // empty table means every code is unmapped, which is the honest
            // answer rather than a fabricated dictionary.
            default => [],
        };
    }
}
