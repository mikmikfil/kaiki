<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * A third party an operator holds credentials for (data-model §2.7).
 *
 * Seven providers across four jobs — taking money, issuing invoices, sending
 * SMS and sending email — deliberately in one enum, because the *storage*
 * question is identical for all of them and the ADR-0004 shape answers it once.
 * What differs is which fields a provider needs, which is
 * {@see self::credentialFields()}, and what "default" means, which is
 * {@see self::isPaymentGateway()}.
 *
 * ## `spec.md` PAY-4's `gateway` is this column, widened
 *
 * ADR-0004 needed to name Viva and Stripe. The other five would otherwise be
 * three more tables or a column group on `tenants` — a rebuild on SQLite (§0) —
 * so the discriminator is `provider`, and payment is one of the things a
 * provider can be rather than the only thing.
 */
enum IntegrationProvider: string
{
    use HasTranslatedLabel;

    // Payments (PAY-1): the operator's own account, always. The platform never
    // holds guest money.
    case Viva = 'viva';
    case Stripe = 'stripe';

    // Greek e-invoicing (§10).
    case Mydata = 'mydata';

    // SMS. Three because Greek operators already have an account with one of
    // them and will not open another to use this product.
    case Apifon = 'apifon';
    case Yuboto = 'yuboto';
    case Twilio = 'twilio';

    // Transactional email.
    case Postmark = 'postmark';

    /**
     * Does `is_default` mean anything for this provider?
     *
     * Only for the two that can both be configured at once and only one of
     * which can take a given checkout. A tenant with myDATA *and* Postmark has
     * no choice to make — they are used for different things — so marking one
     * "default" would be a flag with no reader.
     */
    public function isPaymentGateway(): bool
    {
        return match ($this) {
            self::Viva, self::Stripe => true,
            default => false,
        };
    }

    /** @return list<self> */
    public static function paymentGateways(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $provider): bool => $provider->isPaymentGateway(),
        ));
    }

    /**
     * The secret keys this provider's `credentials` array must carry.
     *
     * Field *names*, never field values — nothing here is a credential, and
     * `NoCredentialLeakTest` scans this file like any other. They live in PHP
     * rather than in a config file because a missing key is a checkout that
     * fails at the gateway with the provider's own unhelpful message, and the
     * form should refuse to save it long before that.
     *
     * `docs/api.md` documents the same shapes for the operator-facing side.
     *
     * @return list<string>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            // Smart Checkout is OAuth2 client credentials plus the source code
            // that identifies which of the merchant's payment sources to use.
            self::Viva => ['client_id', 'client_secret'],
            self::Stripe => ['secret_key', 'publishable_key'],
            // AADE issues a user id and a subscription key, not a password.
            self::Mydata => ['user_id', 'subscription_key'],
            self::Apifon => ['token', 'secret_key'],
            self::Yuboto => ['api_key'],
            self::Twilio => ['account_sid', 'auth_token'],
            self::Postmark => ['server_token'],
        };
    }

    /**
     * Non-secret settings this provider stores in `public_config`.
     *
     * Split from {@see self::credentialFields()} by exactly one question: would
     * showing this to the operator in plaintext, in a log, or in a support
     * ticket be a breach? A Viva source code and an SMS sender name would not;
     * they are configuration an operator has to be able to read back to check.
     *
     * @return list<string>
     */
    public function publicFields(): array
    {
        return match ($this) {
            self::Viva => ['source_code'],
            // `account_id` is not a setting an operator chooses — it is the
            // `acct_…` their Stripe dashboard shows, and it is here rather than
            // in `credentialFields()` because it is not a secret and because
            // `externalAccountId()` reads the external account field out of the
            // public half. It is what resolves a tenant for a Stripe webhook.
            self::Stripe => ['account_id'],
            self::Mydata => ['branch'],
            self::Apifon, self::Yuboto, self::Twilio => ['sender_name'],
            self::Postmark => ['from_address', 'from_name'],
        };
    }

    /**
     * Does this provider sign its webhooks with a secret we hold?
     *
     * Where it does, `webhook_secret` is required — PAY-5 verifies every
     * inbound webhook before parsing it, and a null secret there is not a
     * relaxed check but an unverifiable one.
     */
    public function issuesWebhookSecret(): bool
    {
        return match ($this) {
            self::Stripe, self::Postmark => true,
            // Viva signs with the same OAuth credentials; the SMS vendors and
            // AADE do not call back at all.
            default => false,
        };
    }

    /**
     * The provider's own account identifier, if it puts one in its webhooks.
     *
     * This is what `external_account_id` holds and what resolves a tenant for a
     * webhook that arrives with no tenant context (§2.7). Null means the
     * provider offers nothing to key on, and its webhooks must be resolved from
     * the payment reference instead.
     */
    public function externalAccountField(): ?string
    {
        return match ($this) {
            self::Stripe => 'account_id',
            self::Viva => 'source_code',
            default => null,
        };
    }
}
