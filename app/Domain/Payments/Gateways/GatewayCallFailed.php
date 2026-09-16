<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Data\TranslatableMessage;
use App\Domain\Payments\Support\GatewayErrorDictionary;
use App\Enums\PaymentGatewayName;
use RuntimeException;

/**
 * A gateway call did not complete (spec PAY-12, EXT-2, CNV-11).
 *
 * ## Only for calls that could not finish — a decline is not one
 *
 * A card declined is an *answer*: the gateway worked, the bank said no, and the
 * guest needs a sentence and another card. That comes back as data.
 *
 * This is for the gateway being unreachable, timing out, refusing our
 * credentials, or answering with something unparseable. Different problem,
 * different remedy: retry, then tell the operator their integration is broken.
 *
 * ## It carries the two sentences rather than a message
 *
 * PAY-12's asymmetry, preserved all the way up the stack. A caller that only
 * had `getMessage()` would have to pick one audience, and the one it would pick
 * is whichever is convenient at the call site — which is how raw gateway text
 * ends up on a guest's screen.
 *
 * `getMessage()` itself is the **operator's** English line: it is what lands in
 * a log, and a log is read by somebody debugging rather than by a guest.
 */
final class GatewayCallFailed extends RuntimeException
{
    private function __construct(
        public readonly TranslatableMessage $description,
        public readonly PaymentGatewayName $gateway,
        /**
         * Nothing came back, as against something that came back and said no.
         *
         * A caller has to be able to tell these apart without reading the
         * message: a refusal sends an operator to re-copy their keys, and doing
         * that to somebody whose gateway was merely down for a minute is a wasted
         * afternoon — while a reconciler that read "unreachable" as "unpaid"
         * would cancel bookings during an outage.
         */
        public readonly bool $unreachable = false,
    ) {
        parent::__construct($description->operatorEn);
    }

    public static function forCode(PaymentGatewayName $gateway, string $code): self
    {
        return new self(GatewayErrorDictionary::describe($gateway, $code), $gateway);
    }

    /**
     * The gateway could not be reached at all.
     *
     * Distinct from an error code because there is no code: a connection
     * timeout produces nothing to look up, and treating it as an unmapped code
     * would tell an operator their gateway returned something unrecognised when
     * in fact it returned nothing.
     */
    public static function unreachable(PaymentGatewayName $gateway): self
    {
        return new self(
            TranslatableMessage::mapped(
                'payments.guest.temporary',
                'payments.operator.unreachable',
                replacements: ['gateway' => $gateway->label()],
            ),
            $gateway,
            unreachable: true,
        );
    }
}
