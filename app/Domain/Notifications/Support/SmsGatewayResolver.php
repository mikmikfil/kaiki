<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Support;

use App\Contracts\SmsGateway;
use App\Domain\Notifications\Gateways\NullSmsGateway;
use App\Enums\NotificationProvider;
use App\Models\Tenant;
use Illuminate\Contracts\Container\Container;

/**
 * Which SMS provider this operator chose (spec NTF-2, FIXED).
 *
 * > *…the operator chooses and the platform provides a fallback.*
 *
 * ## Every unknown answer is the fallback, and none of them is an exception
 *
 * A tenant with no setting, a tenant with a setting nobody recognises, a tenant
 * whose provider was removed — all three get {@see NullSmsGateway}, which
 * composes the message, records it and sends nothing.
 *
 * Throwing on any of them would turn a configuration gap into a failed reminder
 * sweep for **every** tenant sharing that worker, which is the shape of outage
 * where one operator's half-finished settings stop everybody else's guests
 * hearing from them.
 *
 * ## The choice lives in `tenants.settings`, not in config
 *
 * It is per operator by definition. The platform's own choice is only the
 * fallback, and it is the null one — the platform does not have an SMS account
 * to lend, because VIS-5's posture about money applies to messaging too: the
 * accounts are the operator's.
 */
final class SmsGatewayResolver
{
    /** The key inside `tenants.settings` an operator's choice is stored under. */
    public const SETTING = 'sms_provider';

    public function __construct(private readonly Container $container) {}

    public function forTenant(?Tenant $tenant): SmsGateway
    {
        $chosen = $this->chosenBy($tenant);

        $class = config("kaiki.notifications.gateways.{$chosen->value}");

        if (! is_string($class) || ! class_exists($class)) {
            return $this->container->make(NullSmsGateway::class);
        }

        /** @var SmsGateway $gateway */
        $gateway = $this->container->make($class);

        return $gateway;
    }

    /**
     * The provider this tenant selected, or the fallback.
     *
     * Public because the panel shows it beside the operator's own setting, and
     * a screen that computed the answer differently from the sender would tell
     * an operator their texts go somewhere they do not.
     */
    public function chosenBy(?Tenant $tenant): NotificationProvider
    {
        $settings = $tenant === null ? [] : $tenant->settings;
        $chosen = $settings[self::SETTING] ?? null;

        if (! is_string($chosen)) {
            return NotificationProvider::NullGateway;
        }

        // `tryFrom`, so a value nobody recognises falls back rather than
        // throwing — see the class docblock.
        return NotificationProvider::tryFrom($chosen) ?? NotificationProvider::NullGateway;
    }
}
