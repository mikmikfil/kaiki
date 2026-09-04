<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `brand_profiles.social_links` — a fixed key set, each value checked for what
 * it is (`docs/data-model.md` §3.10).
 *
 * ## Unknown keys are rejected, and that is a security rule
 *
 * §3.10: *"Unknown keys rejected, so the hosted-page footer cannot be turned
 * into an open redirect list."* The hosted page renders every entry in this
 * column as a link. A column that accepted any key would let anyone who can
 * write it — an API caller with a compromised secret key, an import file —
 * publish arbitrary outbound links on a page carrying the operator's name and
 * taking payments. Five keys is not a UI restriction; it is the whole of what
 * the footer can ever point at.
 *
 * ## Only `http(s)`
 *
 * `javascript:` and `data:` are URLs, and `filter_var` with `FILTER_VALIDATE_URL`
 * accepts neither — but it does accept `ftp://`, `file://` and any other scheme
 * with the right shape, and one of those in an `href` is at best a broken link
 * and at worst a local-file prompt. The scheme is checked explicitly.
 *
 * ## WhatsApp is a phone number
 *
 * E.164: a leading `+`, a non-zero country code, and up to fifteen digits
 * total. Written as a regex rather than pulling in `propaganistas/laravel-phone`
 * — that package is approved (ARC-21) but it exists to *parse* and format
 * national numbers against a country, and nothing here needs that. The format
 * itself is four lines of specification.
 */
final class SocialLinks implements ValidationRule
{
    /**
     * The whole of §3.10. Adding one is a documented change to the data model
     * and to the hosted-page footer, not an edit here.
     *
     * @var list<string>
     */
    public const KEYS = ['website', 'instagram', 'facebook', 'tripadvisor', 'whatsapp'];

    /** The one key that is a phone number rather than a URL. */
    public const PHONE_KEY = 'whatsapp';

    /**
     * @param  string|null  $key  when set, `$value` is that one link rather than
     *                            the whole set
     */
    private function __construct(private readonly ?string $key = null) {}

    /**
     * The whole column: key set and every value.
     *
     * This is the form the Action and the API use, and the only form that can
     * see an **unknown key** — a per-field rule is handed a value and a name,
     * never the shape of the object around it, so it cannot notice a sixth key
     * that nothing asked for.
     */
    public static function forSet(): self
    {
        return new self;
    }

    /**
     * One link, so the panel can put the error beside the box it belongs to.
     *
     * The same split as #15's {@see TranslatableRequired::forLocale()}, for the
     * same reason: a whole-set rule can only report on one field, which means
     * marking the website input red to say the WhatsApp number is malformed.
     */
    public static function forKey(string $key): self
    {
        return new self($key);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->key !== null) {
            $this->validateOne($this->key, $value, $fail);

            return;
        }

        if ($value === null || $value === []) {
            return;
        }

        if (! is_array($value)) {
            $fail('branding.validation.social_links.shape')->translate();

            return;
        }

        foreach ($value as $key => $link) {
            if (! is_string($key) || ! in_array($key, self::KEYS, true)) {
                $fail('branding.validation.social_links.unknown_key')->translate([
                    'key' => is_string($key) ? $key : (string) $key,
                    'allowed' => implode(', ', self::KEYS),
                ]);

                continue;
            }

            $this->validateOne($key, $link, $fail);
        }
    }

    /** One key's value, shared by both forms of the rule so they cannot disagree. */
    private function validateOne(string $key, mixed $value, Closure $fail): void
    {
        // An empty box is "I do not have one", not a failure. `clean()` drops
        // these before saving, so the column holds only links that exist.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value)) {
            $fail('branding.validation.social_links.shape')->translate();

            return;
        }

        $valid = $key === self::PHONE_KEY
            ? self::isE164($value)
            : self::isHttpUrl($value);

        if (! $valid) {
            $fail($key === self::PHONE_KEY
                ? 'branding.validation.social_links.phone'
                : 'branding.validation.social_links.url')->translate(['key' => $key]);
        }
    }

    public static function isHttpUrl(string $value): bool
    {
        $trimmed = trim($value);

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /** E.164: `+`, a country code that cannot start with 0, 15 digits at most. */
    public static function isE164(string $value): bool
    {
        return preg_match('/^\+[1-9]\d{6,14}$/', trim($value)) === 1;
    }

    /**
     * Drop the keys an operator left empty, so "no Facebook page" is an absent
     * key rather than an empty string the footer has to test for.
     *
     * @param  array<string, mixed>  $links
     * @return array<string, string>
     */
    public static function clean(array $links): array
    {
        $cleaned = [];

        foreach (self::KEYS as $key) {
            $value = $links[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $cleaned[$key] = trim($value);
            }
        }

        return $cleaned;
    }
}
