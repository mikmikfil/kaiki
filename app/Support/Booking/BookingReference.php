<?php

declare(strict_types=1);

namespace App\Support\Booking;

use InvalidArgumentException;
use Random\RandomException;

/**
 * The human booking reference — `KAI-7F3K2` (spec BKG-3, BKG-4, ADR-0007).
 *
 * ## Why a value object rather than a helper
 *
 * ADR-0007 item 5 asks for one, and the reason is that four different surfaces
 * have to agree about this string: the generator, the operator's search box,
 * the guest typing it off a printed ticket, and the validation rule that tells
 * them they got it wrong. Four copies of an alphabet is three chances to
 * disagree, and the disagreement shows up as "your reference is not valid" for
 * a reference that plainly is.
 *
 * ## The alphabet is the whole point
 *
 * **Thirty** symbols: digits and uppercase letters, minus `0`, `O`, `I`, `1`,
 * `L` and `U`. The first five are the pairs that get misread; `U` is excluded
 * because Crockford's base32 does, so that an operator who reaches for a
 * standard decoder gets the same answer we do.
 *
 * Thirty, and not the thirty-one BKG-3 item 2 asserts. The requirement states
 * the *rule* and then a count and a combination total that belong to a
 * 31-symbol alphabet — eight digits plus twenty-two letters is thirty, and
 * 30^5 is 24.3 million rather than 28.6. The rule is the specification and the
 * arithmetic was a slip; #80 corrected the two numbers in `docs/spec.md` with
 * the reason in `CHANGELOG.md`, and `BookingReferenceTest` asserts the count so
 * the two cannot drift apart again.
 *
 * That is the real-world failure ADR-0007 names — *"a guest reading `0` as `O`
 * on a windy pier"* — and it is why {@see self::normalise()} does not merely
 * uppercase: it maps the confusable characters onto their intended twins, so a
 * guest who types `KAI-O7F3K` finds `KAI-07F3K`… except that `0` is not in the
 * alphabet either, so the mapping runs the other way. `O` and `0` both become
 * nothing they could have meant, and the lookup fails honestly rather than
 * finding a stranger's booking.
 *
 * ## Uniqueness is per tenant, and that is a rule with a consequence
 *
 * BKG-3 item 3: any cross-tenant surface must display the operator alongside
 * the reference, because two operators can hold `KAI-7F3K2` at the same time.
 * 24.3 million combinations is generous per tenant and meaningless globally.
 */
final class BookingReference
{
    /**
     * Thirty unambiguous symbols (ADR-0007 item 2).
     *
     * Digits and uppercase letters minus `0 O I 1 L U`. See the class docblock
     * for why this is thirty and the spec said thirty-one.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * What a guest's typo most likely meant.
     *
     * Applied before the alphabet check, so `KAI-O7F3K` is read as the `0` the
     * guest saw — and then rejected, because `0` is not in the alphabet and
     * therefore never generated. The mapping's job is to make a wrong reference
     * fail *as a wrong reference*, not to quietly resolve to a different real
     * booking.
     *
     * @var array<string, string>
     */
    private const CONFUSABLE = [
        'O' => '0',
        'I' => '1',
        'L' => '1',
        'U' => 'V',
    ];

    private function __construct(public readonly string $value) {}

    /**
     * A fresh random reference.
     *
     * @param  int|null  $length  random characters after the prefix; defaults to
     *                            `kaiki.booking.reference_length`
     *
     * @throws RandomException when the system has no usable source of randomness
     */
    public static function generate(?int $length = null): self
    {
        $length ??= (int) config('kaiki.booking.reference_length');
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;

        $random = '';

        for ($i = 0; $i < $length; $i++) {
            // `random_int`, not `mt_rand` or `Str::random`. A predictable
            // reference is not a security hole on its own — the reference alone
            // opens nothing — but `/b/{manage_token}` is right beside it, and a
            // codebase where one identifier is guessable is one where the next
            // is too.
            $random .= $alphabet[random_int(0, $max)];
        }

        return new self(self::prefix() . '-' . $random);
    }

    /**
     * Read a reference a human typed.
     *
     * @throws InvalidArgumentException when it cannot be one — callers that
     *                                  expect bad input use {@see self::tryFrom()}
     */
    public static function from(string $value): self
    {
        $normalised = self::normalise($value);

        if (! self::isValid($normalised)) {
            // The message is for a developer; the sentence a guest sees comes
            // from `BookingReferenceFormat` and a lang file (CNV-11).
            throw new InvalidArgumentException('Not a booking reference: ' . $value);
        }

        return new self($normalised);
    }

    public static function tryFrom(string $value): ?self
    {
        $normalised = self::normalise($value);

        return self::isValid($normalised) ? new self($normalised) : null;
    }

    /**
     * Turn what a guest typed into what we would have stored.
     *
     * Uppercases, strips everything that is not a letter or a digit — which
     * removes the hyphen, the spaces somebody added, and the invisible
     * characters a PDF copy-paste brings with it — maps the confusables, then
     * puts the hyphen back where it belongs.
     *
     * Stripping the hyphen and re-adding it rather than preserving it is what
     * makes `kai 7f3k2`, `KAI7F3K2` and `kai—7f3k2` (em dash) all resolve.
     */
    public static function normalise(string $value): string
    {
        $bare = strtoupper(preg_replace('/[^A-Za-z0-9]/u', '', $value) ?? '');
        $prefix = self::prefix();

        if (! str_starts_with($bare, $prefix)) {
            return strtr($bare, self::CONFUSABLE);
        }

        // **The confusable map is applied to the random part only**, and the
        // default prefix is why: `KAI` contains an `I`, which is one of the six
        // characters the alphabet excludes. Mapping across the whole string
        // rewrote every reference's own prefix to `KA1` and then failed to
        // recognise it — so `KAI-7F3K2`, a reference this class had just
        // generated, did not validate. The prefix is ours and is never
        // ambiguous; only what follows it was ever read off a printed ticket.
        return $prefix . '-' . strtr(substr($bare, strlen($prefix)), self::CONFUSABLE);
    }

    /** Is this exactly the shape we generate? */
    public static function isValid(string $normalised): bool
    {
        $prefix = preg_quote(self::prefix(), '/');
        $alphabet = preg_quote(self::ALPHABET, '/');
        $min = (int) config('kaiki.booking.reference_length');
        // The widened form is one character longer, and a reference generated
        // after five collisions is exactly as valid as one generated first try.
        $max = $min + 1;

        return preg_match('/^' . $prefix . '-[' . $alphabet . ']{' . $min . ',' . $max . '}$/', $normalised) === 1;
    }

    /**
     * The brand prefix.
     *
     * Config, per ADR-0007's consequences: *"the global rename in the brief
     * header changes one constant, not the schema"*. Uppercased here so that a
     * lowercase env value cannot produce references that fail their own
     * validation.
     */
    public static function prefix(): string
    {
        return strtoupper((string) config('kaiki.booking.reference_prefix'));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
