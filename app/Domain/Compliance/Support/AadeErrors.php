<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Support;

use App\Domain\Compliance\Data\MyDataResult;
use App\Mail\GuestMail;

/**
 * What an AADE refusal means, in Greek (spec MYD-9).
 *
 * ## The fallback is the feature
 *
 * The mapped table is deliberately short — see `docs/compliance/mydata-errors.md`
 * for why, and for the marker beside every row saying where it came from. What
 * makes this class useful today is not the entries; it is that an **unmapped**
 * code still reaches the operator as AADE's own message plus a sentence telling
 * them what to do with it.
 *
 * A confidently wrong Greek explanation is worse than none, because an operator
 * will act on it. So a code nobody has verified gets the honest answer rather
 * than a plausible one.
 *
 * ## Permanent and transient, and why the list is explicit
 *
 * {@see MyDataResult} already separates *refused* from *unreachable*, and the
 * gateway sets that from the transport. This adds the case the transport cannot
 * see: an HTTP 401 arrives looking like any other failure, and retrying a wrong
 * subscription key eight times over a day achieves nothing except filling a
 * failure feed.
 *
 * The list is a constant rather than a guess at ranges, because getting it wrong
 * costs in both directions — a permanent error retried is noise, and a transient
 * error treated as permanent is an invoice that would have gone through on the
 * next attempt and now needs a person.
 */
final class AadeErrors
{
    /** The key every unmapped code falls back to. */
    public const UNKNOWN = 'unknown';

    /**
     * Codes that must stop the retry ladder.
     *
     * Bad credentials, and Kaiki's own "nothing was sent" outcome. Everything
     * else the transport reports is assumed retryable, because a timeout is the
     * overwhelmingly common transport failure and it is exactly the one worth
     * trying again.
     */
    private const PERMANENT = [
        'http_401',
        'http_403',
        'not_configured',
    ];

    /**
     * The explanation, in whichever language is being read.
     *
     * `$raw` is AADE's own message, and it is passed through **verbatim** in the
     * unknown case rather than summarised: an operator reading it to their
     * accountant needs the words the tax authority used, not our paraphrase.
     */
    public static function explain(?string $code, ?string $raw = null, ?string $locale = null): string
    {
        $key = self::key($code);
        $raw = trim((string) $raw);

        if ($key === self::UNKNOWN) {
            return $raw === ''
                ? __('mydata.errors.unknown_without_message', [], $locale)
                : __('mydata.errors.unknown', ['message' => $raw], $locale);
        }

        return __("mydata.errors.{$key}", [], $locale);
    }

    /**
     * The Greek sentence, whoever is asking.
     *
     * `invoices.last_error_message_el` is Greek by its own name, and it is
     * written by a **queue worker** — which holds whatever locale the last job
     * on it happened to set. Without pinning, an operator's failure feed would
     * carry English on a busy morning and Greek on a quiet one, from the same
     * error, and nobody would ever work out why.
     *
     * The same defect {@see GuestMail} was written to avoid, in the
     * same place: a worker with no request behind it has no locale worth
     * trusting.
     */
    public static function inGreek(?string $code, ?string $raw = null): string
    {
        return self::explain($code, $raw, 'el');
    }

    /**
     * Should the sweeper try this one again?
     *
     * Both halves matter: a result the gateway already called non-retryable
     * stays non-retryable — the transport knows things this table does not —
     * and a retryable one is checked against the permanent list.
     */
    public static function isRetryable(MyDataResult $result): bool
    {
        if (! $result->retryable) {
            return false;
        }

        return ! in_array((string) $result->errorCode, self::PERMANENT, strict: true);
    }

    /**
     * The lang key for a code, or `unknown`.
     *
     * Checked against the translator rather than against a hardcoded list, so
     * adding a code is one entry in two lang files and nothing here — which is
     * what `docs/compliance/mydata-errors.md` tells the next person to do.
     */
    private static function key(?string $code): string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return self::UNKNOWN;
        }

        // A code is a number or a short transport shape. Anything else is
        // somebody's raw message arriving where a code belongs, and looking it
        // up as a translation key would be a path traversal in a lang file.
        if (preg_match('/^[a-z0-9_]{1,32}$/i', $code) !== 1) {
            return self::UNKNOWN;
        }

        return trans()->has("mydata.errors.{$code}") ? $code : self::UNKNOWN;
    }
}
