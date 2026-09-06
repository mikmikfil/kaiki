<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Gateways;

use App\Contracts\SmsGateway;
use App\Domain\Integrations\Support\CredentialRepository;
use App\Domain\Notifications\Data\SmsResult;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\NotificationProvider;
use App\Models\IntegrationCredential;
use App\Support\Tenancy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Twilio, the fallback that reaches everywhere (spec NTF-2).
 *
 * ## Second by design, not by preference
 *
 * NTF-2 lists Apifon first because a Greek number through a Greek provider
 * costs a fraction of the same message through Twilio, and an operator texting
 * a full boat notices. Twilio earns its place on the other axis: it delivers to
 * numbers Apifon does not, which matters the moment a guest is German.
 *
 * ## No Twilio SDK, and that is deliberate
 *
 * One `POST` with basic auth. Adding `twilio/sdk` would put a package outside
 * §3.2's approved list into the dependency tree for a single form-encoded
 * request — an ADR, per ADR-0019, for something that fits in this file.
 *
 * ## The price arrives later, and the log has a column for it
 *
 * Twilio's create response carries a `price` of `null` and fills it in
 * asynchronously; the number only becomes real on a status callback. So
 * `costCents` is null here rather than zero, which is exactly the distinction
 * {@see SmsResult} draws — an operator summing a column of zeros would think
 * their season was free.
 */
final class TwilioSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CredentialRepository $credentials,
    ) {}

    public function send(string $to, string $body): SmsResult
    {
        $credential = $this->credentials->find(IntegrationProvider::Twilio, CredentialEnvironment::Live);

        if (! $credential instanceof IntegrationCredential) {
            return SmsResult::failed('twilio_not_configured');
        }

        $secrets = $credential->credentials ?? [];
        $sid = (string) ($secrets['account_sid'] ?? '');

        try {
            $response = $this->http
                ->withBasicAuth($sid, (string) ($secrets['auth_token'] ?? ''))
                ->asForm()
                ->timeout((int) config('kaiki.notifications.timeout_seconds', 10))
                ->post(
                    (string) config('kaiki.notifications.twilio.host') . "/2010-04-01/Accounts/{$sid}/Messages.json",
                    [
                        'To' => $to,
                        'From' => (string) ($secrets['from'] ?? ''),
                        'Body' => $body,
                    ],
                );
        } catch (Throwable $exception) {
            Log::warning('notifications.sms_unreachable', [
                'provider' => NotificationProvider::Twilio->value,
                'tenant_id' => Tenancy::id(),
                'exception' => $exception::class,
            ]);

            return SmsResult::failed('twilio_unreachable');
        }

        if ($response->failed()) {
            $payload = $response->json();
            $code = is_array($payload) ? ($payload['code'] ?? null) : null;

            return SmsResult::failed(is_scalar($code) ? (string) $code : (string) $response->status());
        }

        $payload = $response->json();
        $sidOfMessage = is_array($payload) ? ($payload['sid'] ?? null) : null;

        // No cost. See the class docblock: Twilio fills the price in later, and
        // a zero here would be a lie an operator adds up.
        return SmsResult::sent(is_string($sidOfMessage) ? $sidOfMessage : (string) $response->status());
    }

    public function provider(): NotificationProvider
    {
        return NotificationProvider::Twilio;
    }
}
