<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Channels\Support\ChannelResolver;
use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\Tenant;

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
 * ADR-0004 needed to name the gateways. The other five would otherwise be
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

    // Greek e-invoicing (§10).
    case Mydata = 'mydata';

    // SMS. Three because Greek operators already have an account with one of
    // them and will not open another to use this product.
    case Apifon = 'apifon';
    case Yuboto = 'yuboto';
    case Twilio = 'twilio';

    // Transactional email.
    case Postmark = 'postmark';

    // The first OTA sales channel (ADR-0034). Unlike every case above it, this
    // is not a vendor an operator picks from a menu — it is a contract they
    // signed with GetYourGuide themselves, which the platform then switches on
    // for them. {@see \App\Enums\ChannelKey} is the channel side of it; this
    // case exists so the credentials live in the same encrypted store, behind
    // the same screen, with the same masking and the same verify button.
    case GetYourGuide = 'getyourguide';

    /**
     * Does `is_default` mean anything for this provider?
     *
     * Only for the providers that can both be configured at once and only
     * one of which can take a given checkout. A tenant with myDATA *and* Postmark has
     * no choice to make — they are used for different things — so marking one
     * "default" would be a flag with no reader.
     */
    public function isPaymentGateway(): bool
    {
        return match ($this) {
            self::Viva => true,
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
            //
            // And the Basic-auth pair beside them, because Viva splits its APIs
            // across two authentication schemes: orders are created with an
            // OAuth2 token minted from the client credentials, while the webhook
            // **verification key** is only readable from
            // `{checkout host}/api/messages/config/token` with Merchant ID and
            // API key. Probed on 2026-09-16: that path answers 401 to a bearer
            // token and 404 on the api host, so the client credentials cannot
            // reach it at any address. All four are on one dashboard page
            // (Settings → API Access), which is the thing that matters — an
            // operator copies, and never computes.
            self::Viva => ['client_id', 'client_secret', 'merchant_id', 'api_key'],
            // AADE issues a user id and a subscription key, not a password.
            self::Mydata => ['user_id', 'subscription_key'],
            self::Apifon => ['token', 'secret_key'],
            self::Yuboto => ['api_key'],
            self::Twilio => ['account_sid', 'auth_token'],
            self::Postmark => ['server_token'],
            // The key for the calls **we** make to `supplier-api.getyourguide.
            // com` — availability pushes and ticket redemptions. GetYourGuide
            // issues it to the supplier, so the operator pastes it.
            //
            // The other half of this integration is authentication in the
            // opposite direction: their servers call our endpoints with HTTP
            // Basic, using a username and password *Kaiki* generates and the
            // operator gives to GetYourGuide. Those are deliberately **not**
            // here yet. They authenticate endpoints that do not exist until
            // #135, and a screen handing somebody credentials for a URL that
            // answers 404 is a screen that lies.
            self::GetYourGuide => ['api_key'],
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
            self::Mydata => ['branch'],
            self::Apifon, self::Yuboto, self::Twilio => ['sender_name'],
            self::Postmark => ['from_address', 'from_name'],
            // GetYourGuide's own id for this supplier. Not a secret — it is on
            // their dashboard and in every request they make — and the operator
            // has to be able to read it back to check it against theirs.
            self::GetYourGuide => ['supplier_id'],
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
            // Viva calls it a "webhook verification key": a shared secret the
            // receiver proves it holds, presented in `X-Viva-Verification`.
            //
            // This said `false` until the Stripe removal, and it was wrong the
            // whole time — `VivaSmartCheckoutGateway::verifyWebhook()` has always
            // read `webhook_secret` and refused when it is missing, so an
            // operator whose form never asked for the key had every Viva webhook
            // silently rejected. It mattered less while a second gateway existed;
            // it is now the only way money gets confirmed.
            self::Viva, self::Postmark => true,
            // The SMS vendors and AADE do not call back at all.
            default => false,
        };
    }

    /**
     * The providers an operator is offered on the connections screen.
     *
     * Not every case here: the enum is what the *platform* can store credentials
     * for, and the form is what one operator has any business setting up. As of
     * 2026-09-16 that is the gateway and AADE, by the product owner's decision.
     *
     * The SMS vendors are offered only to an operator the platform has switched
     * SMS on for (2026-09-17, `Tenant::usesSms()`): to anyone else a text
     * gateway is a field that changes nothing. Postmark is never offered,
     * because email is sent by the platform's own account rather than per
     * operator. The cases stay, because rows already written keep working and
     * the credential machinery is unchanged; only the menu is shorter.
     *
     * @return array<string, string> value => label, for the select
     */
    public static function operatorOptions(?Tenant $tenant = null): array
    {
        $offered = [self::Viva, self::Mydata];

        if ($tenant instanceof Tenant && $tenant->usesSms()) {
            array_push($offered, self::Apifon, self::Yuboto, self::Twilio);
        }

        // GetYourGuide, on the same principle and through the same door as the
        // SMS vendors — offered only where it can do something. Asked through
        // the resolver rather than `Tenant::usesGetYourGuide()` directly,
        // because the platform's `channel_manager` flag is shut until
        // certification passes and an operator must not be invited to paste
        // credentials into a screen that cannot use them yet (ADR-0034).
        if ($tenant instanceof Tenant && app(ChannelResolver::class)->isPermittedFor($tenant, ChannelKey::GetYourGuide)) {
            $offered[] = self::GetYourGuide;
        }

        $options = [];

        foreach ($offered as $provider) {
            $options[$provider->value] = $provider->label();
        }

        return $options;
    }

    /**
     * Can we fetch this provider's webhook secret ourselves?
     *
     * Viva's verification key is retrievable with the OAuth2 token the client
     * credentials already mint, which is why their own WordPress plugin asks an
     * operator for a client id and a client secret and nothing else. Asking for
     * it here was asking an operator to go and find a value we can read — the
     * one field on the form nobody could locate without a support call.
     *
     * Postmark's is issued when the operator creates the webhook and there is
     * no API to read it back, so that one is still a field.
     */
    public function fetchesWebhookSecret(): bool
    {
        return match ($this) {
            self::Viva => true,
            default => false,
        };
    }

    /**
     * Must the *operator* supply the webhook secret?
     *
     * The provider still issues one — {@see self::issuesWebhookSecret()} is
     * unchanged and PAY-5 still refuses an unverifiable webhook. This is the
     * narrower question the form asks.
     */
    public function requiresWebhookSecretFromOperator(): bool
    {
        return $this->issuesWebhookSecret() && ! $this->fetchesWebhookSecret();
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
            self::Viva => 'source_code',
            default => null,
        };
    }
}
