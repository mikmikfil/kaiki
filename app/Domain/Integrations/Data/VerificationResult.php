<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Data;

use App\Models\IntegrationCredential;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * What a test call against a provider concluded (spec EXT-2, CNV-11, PAY-12).
 *
 * ## A refusal is a result, not an exception
 *
 * The single most likely outcome of pressing "verify" is that the operator
 * pasted the wrong key — that is what the button is *for*. Modelling that as a
 * thrown exception would mean the ordinary path runs through a catch block, and
 * exception messages are written by the provider in English for a developer.
 *
 * ## The message is a lang key, never a sentence
 *
 * CNV-11: *"error messages that reach an operator exist in Greek and English
 * and are drawn from lang files, never from an exception message"*. So a
 * verifier returns the key of the sentence and the arguments to fill it, and
 * the panel translates at the moment of rendering — which is the only moment
 * the operator's locale is known.
 *
 * `providerDetail` is the provider's own raw text, kept for the operator's own
 * copy-paste into a support ticket with the vendor. It is stored in
 * `last_error` beside the translated sentence and is **never** the thing shown
 * on its own, because PAY-12 is explicit that raw gateway text is not an error
 * message.
 */
final class VerificationResult extends Data
{
    /**
     * @param  array<string, string>  $messageArguments  replacements for the lang line
     */
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $messageKey = null,
        public readonly array $messageArguments = [],
        public readonly ?string $providerDetail = null,
    ) {}

    public static function success(): self
    {
        return new self(succeeded: true);
    }

    /**
     * @param  array<string, string>  $arguments
     */
    public static function failure(string $messageKey, array $arguments = [], ?string $providerDetail = null): self
    {
        return new self(
            succeeded: false,
            messageKey: $messageKey,
            messageArguments: $arguments,
            providerDetail: $providerDetail,
        );
    }

    /**
     * The sentence an operator reads, in the current locale.
     *
     * Truncated to the column width here rather than at the call site, because
     * the caller that forgets is the one storing a gateway's HTML error page.
     */
    public function message(): string
    {
        if ($this->messageKey === null) {
            return '';
        }

        $message = __($this->messageKey, $this->messageArguments);

        return Str::limit(is_string($message) ? $message : $this->messageKey, IntegrationCredential::MAX_ERROR_LENGTH - 1);
    }

    /**
     * What `last_error` stores: the translated sentence, plus the provider's own
     * words in brackets where it gave any.
     */
    public function storedError(): ?string
    {
        if ($this->succeeded) {
            return null;
        }

        $message = $this->message();

        if ($this->providerDetail === null || trim($this->providerDetail) === '') {
            return $message;
        }

        return Str::limit(
            $message . ' (' . trim($this->providerDetail) . ')',
            IntegrationCredential::MAX_ERROR_LENGTH - 1,
        );
    }
}
