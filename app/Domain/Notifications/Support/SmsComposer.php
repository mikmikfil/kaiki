<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Support;

/**
 * How long a text message really is (spec NTF-5).
 *
 * > *SMS bodies are at most 160 GSM-7 characters where possible; **Greek text
 * > falls back to UCS-2** and the composer MUST warn the operator about segment
 * > count. Every SMS includes the meeting point and time and a short link.*
 *
 * ## The Greek half is the whole problem
 *
 * GSM-7 packs 160 characters into a segment. It does **not** contain the Greek
 * lowercase alphabet — only a handful of uppercase Greek letters that happen to
 * look like Latin ones. So an ordinary Greek sentence forces UCS-2, and a
 * segment becomes **70** characters rather than 160.
 *
 * That is the number an operator is billed on, and it is why this class exists
 * rather than a `strlen()` at the call site: *"Καλησπέρα, η εκδρομή σας είναι
 * αύριο στις 09:00"* is 44 characters and **one** segment; the same sentence
 * with the meeting point and a link is three, and costs three times as much.
 * An operator who is not told that finds out on an invoice.
 *
 * ## Concatenation costs, and the arithmetic is not obvious
 *
 * A multi-segment message spends 6 characters per segment on a header, so the
 * usable length per segment drops to 153 (GSM-7) or 67 (UCS-2). Dividing the
 * total by 160 is the mistake everybody makes, and it under-counts long
 * messages by exactly the amount that matters.
 *
 * ## Truncation protects the meeting point, not the prose
 *
 * NTF-5 fixes what an SMS must carry: where to be, when, and a link. So
 * {@see self::compose()} builds those **first** and truncates the operator's
 * own preamble, rather than cutting the end off and losing the link — a text
 * that arrives without a meeting point is a phone call.
 */
final class SmsComposer
{
    public const GSM7_SINGLE = 160;

    public const GSM7_CONCATENATED = 153;

    public const UCS2_SINGLE = 70;

    public const UCS2_CONCATENATED = 67;

    /**
     * The GSM-7 alphabet, as one string.
     *
     * Including the escape-table characters (`^{}\[~]|€`), each of which costs
     * **two** septets rather than one — which is why {@see self::septets()}
     * counts rather than measuring length.
     */
    private const GSM7 = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    private const GSM7_ESCAPED = '^{}\\[~]|€';

    /**
     * Can this body be sent as GSM-7 at all?
     *
     * One character outside the alphabet forces the **whole** message to UCS-2 —
     * there is no mixing. A single accented Greek vowel in an otherwise Latin
     * message more than halves its capacity, which is the surprise this answers.
     */
    public static function isGsm7(string $body): bool
    {
        foreach (self::characters($body) as $character) {
            if (! str_contains(self::GSM7, $character) && ! str_contains(self::GSM7_ESCAPED, $character)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The billable length: septets for GSM-7, code units for UCS-2.
     *
     * The escape-table characters count double in GSM-7. A message of 160 `€`
     * signs is two segments, not one, and a naive `strlen` says otherwise.
     */
    public static function units(string $body): int
    {
        if (! self::isGsm7($body)) {
            // UCS-2 counts **code units**, so an emoji outside the basic plane
            // costs two. `mb_strlen` in UTF-16 is exactly that count.
            return (int) (strlen((string) mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')) / 2);
        }

        $units = 0;

        foreach (self::characters($body) as $character) {
            $units += str_contains(self::GSM7_ESCAPED, $character) ? 2 : 1;
        }

        return $units;
    }

    /**
     * How many segments the operator will be billed for (NTF-5).
     *
     * The concatenation header is why this is not a division by 160: past one
     * segment, six characters of every segment belong to the header.
     */
    public static function segments(string $body): int
    {
        $units = self::units($body);
        $gsm = self::isGsm7($body);

        $single = $gsm ? self::GSM7_SINGLE : self::UCS2_SINGLE;

        if ($units <= $single) {
            return $units === 0 ? 0 : 1;
        }

        $perSegment = $gsm ? self::GSM7_CONCATENATED : self::UCS2_CONCATENATED;

        return (int) ceil($units / $perSegment);
    }

    /** The encoding an operator is told about, in the words a bill uses. */
    public static function encoding(string $body): string
    {
        return self::isGsm7($body) ? 'GSM-7' : 'UCS-2';
    }

    /**
     * Build an SMS that keeps what NTF-5 says it must keep.
     *
     * The meeting point, the time and the link are assembled first and never
     * truncated; whatever budget is left goes to the lead sentence. A message
     * that lost its link to fit is a message that did not need sending.
     *
     * @param  int  $maxSegments  the ceiling the operator is willing to pay for
     */
    public static function compose(
        string $lead,
        string $meetingPoint,
        string $when,
        string $link,
        int $maxSegments = 2,
    ): string {
        $essential = trim("{$meetingPoint} {$when} {$link}");

        $body = trim("{$lead} {$essential}");

        if (self::segments($body) <= $maxSegments) {
            return $body;
        }

        // Trim the lead one character at a time rather than computing a cut
        // point: the budget is in septets or code units, and a UTF-8 byte offset
        // is neither. Slow, and it runs once per message.
        $trimmedLead = $lead;

        while ($trimmedLead !== '' && self::segments(trim("{$trimmedLead}… {$essential}")) > $maxSegments) {
            $trimmedLead = mb_substr($trimmedLead, 0, mb_strlen($trimmedLead) - 1);
        }

        $trimmedLead = rtrim($trimmedLead);

        return $trimmedLead === '' ? $essential : trim("{$trimmedLead}… {$essential}");
    }

    /**
     * @return list<string>
     */
    private static function characters(string $body): array
    {
        /** @var list<string> $characters */
        $characters = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $characters;
    }
}
