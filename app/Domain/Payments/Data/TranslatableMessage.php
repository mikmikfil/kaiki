<?php

declare(strict_types=1);

namespace App\Domain\Payments\Data;

use Spatie\LaravelData\Data;

/**
 * A gateway error, said twice to two different people (spec PAY-12, CNV-11).
 *
 * ## Two audiences, and conflating them is the failure
 *
 * PAY-12 asks for *"plain Greek and English messages for both the guest
 * (generic and reassuring) and the operator (specific and actionable)"*, and
 * the asymmetry is the whole requirement:
 *
 * - A **guest** whose card was declined needs to know to try another card. They
 *   do not need `card_declined: insufficient_funds`, which tells a stranger
 *   something about their bank balance and gives them nothing to do about it.
 * - An **operator** looking at a failed payment in the panel needs exactly that
 *   detail, because they are the one who has to decide whether to chase it.
 *
 * One message for both means either the guest is told too much or the operator
 * is told nothing.
 *
 * ## Both languages, always, and never at the moment of rendering
 *
 * `docs/api.md` §4.1 requires both strings in every API error because a guest
 * can hit a refusal mid-locale-switch. `payments.failure_message_el` and
 * `failure_message_en` are **columns** for the same reason plus one more: the
 * mapping may change afterwards, and what the operator was told at the time is
 * part of the record.
 *
 * ## An unmapped code degrades in both directions
 *
 * The acceptance criterion that keeps this honest. A code no dictionary knows
 * gives the guest the **generic** message — never nothing, never the raw code —
 * and the operator the raw code **marked as unmapped**, so the gap is visible
 * to the only person who can report it rather than silently swallowed.
 */
final class TranslatableMessage extends Data
{
    private function __construct(
        public readonly string $guestEl,
        public readonly string $guestEn,
        public readonly string $operatorEl,
        public readonly string $operatorEn,
        /** The gateway's own code, for the log and the operator's line. Never a guest's. */
        public readonly ?string $code = null,
        /** True when no dictionary entry matched — see the class docblock. */
        public readonly bool $isUnmapped = false,
    ) {}

    /**
     * A mapped code: four sentences from lang files (CNV-11).
     *
     * @param  string  $guestKey  a `payments.guest.*` key
     * @param  string  $operatorKey  a `payments.operator.*` key
     * @param  array<string, string>  $replacements
     */
    public static function mapped(string $guestKey, string $operatorKey, ?string $code = null, array $replacements = []): self
    {
        return new self(
            guestEl: (string) trans($guestKey, $replacements, 'el'),
            guestEn: (string) trans($guestKey, $replacements, 'en'),
            operatorEl: (string) trans($operatorKey, $replacements, 'el'),
            operatorEn: (string) trans($operatorKey, $replacements, 'en'),
            code: $code,
        );
    }

    /**
     * A code no dictionary knows.
     *
     * The guest sees the ordinary "something went wrong with the payment"
     * sentence — they are not owed a taxonomy — and the operator sees the raw
     * code with a note saying it is unrecognised, which is the difference
     * between a support ticket that can be answered and one that cannot.
     */
    public static function unmapped(string $code): self
    {
        return new self(
            guestEl: (string) trans('payments.guest.generic', [], 'el'),
            guestEn: (string) trans('payments.guest.generic', [], 'en'),
            operatorEl: (string) trans('payments.operator.unmapped', ['code' => $code], 'el'),
            operatorEn: (string) trans('payments.operator.unmapped', ['code' => $code], 'en'),
            code: $code,
            isUnmapped: true,
        );
    }

    /** The guest's sentence in one locale. */
    public function forGuest(string $locale): string
    {
        return $locale === 'el' ? $this->guestEl : $this->guestEn;
    }

    /** The operator's sentence in one locale. */
    public function forOperator(string $locale): string
    {
        return $locale === 'el' ? $this->operatorEl : $this->operatorEn;
    }
}
