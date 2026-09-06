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
 * Apifon, the Greek SMS provider (spec NTF-2).
 *
 * ## Why the Greek one is first among the three
 *
 * Apifon and Yuboto are the two providers Greek operators already have accounts
 * with, and a Greek mobile number reached through a Greek provider costs a
 * fraction of what it costs through Twilio. For an operator sending a
 * pre-departure text to every guest on a full boat, that difference is the
 * whole SMS budget.
 *
 * ## The sender name is the operator's, and it is theirs to register
 *
 * Greek networks require an alphanumeric sender id to be registered before it
 * will deliver. So it comes from the operator's own credential rather than from
 * config — a platform-wide sender would mean every operator's texts arriving
 * under somebody else's name, which is the sort of thing that gets a short code
 * blocked.
 *
 * ## No endpoint is written into this class
 *
 * `config('kaiki.notifications.apifon')`, per `CLAUDE.md`. A sandbox, a staging
 * box and a test run each point somewhere different without touching code — and
 * the suite points them at a host that cannot resolve.
 */
final class ApifonSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CredentialRepository $credentials,
    ) {}

    public function send(string $to, string $body): SmsResult
    {
        $credential = $this->credentials->find(IntegrationProvider::Apifon, CredentialEnvironment::Live);

        if (! $credential instanceof IntegrationCredential) {
            // Not an exception: an operator who has selected Apifon and not
            // finished configuring it needs to read that in their own log, not
            // to have a reminder sweep fall over.
            return SmsResult::failed('apifon_not_configured');
        }

        $secrets = $credential->credentials ?? [];

        try {
            $response = $this->http
                ->withToken((string) ($secrets['token'] ?? ''))
                ->timeout((int) config('kaiki.notifications.timeout_seconds', 10))
                ->post((string) config('kaiki.notifications.apifon.host') . '/services/api/v1/sms/send', [
                    'subscribers' => [['number' => $this->digitsOf($to)]],
                    'message' => [
                        'text' => $body,
                        // The operator's registered sender name — see the class
                        // docblock. Never a platform-wide default.
                        'sender_id' => (string) ($secrets['sender_id'] ?? ''),
                    ],
                ]);
        } catch (Throwable $exception) {
            // The class and the tenant, never the request: an HTTP client's
            // exception message carries the authenticated call it made, and a
            // guest's phone number with it (SEC-9).
            Log::warning('notifications.sms_unreachable', [
                'provider' => NotificationProvider::Apifon->value,
                'tenant_id' => Tenancy::id(),
                'exception' => $exception::class,
            ]);

            return SmsResult::failed('apifon_unreachable');
        }

        if ($response->failed()) {
            $body = $response->json();
            $code = is_array($body) ? ($body['status']['message'] ?? null) : null;

            return SmsResult::failed(is_scalar($code) ? (string) $code : (string) $response->status());
        }

        $payload = $response->json();
        $reference = is_array($payload) ? ($payload['reference'] ?? null) : null;

        return SmsResult::sent(
            is_string($reference) ? $reference : (string) $response->status(),
            // Apifon reports a price per request in euros; recorded in cents,
            // and **absent rather than zero** when it does not — see
            // `SmsResult`.
            self::costOf($payload),
        );
    }

    public function provider(): NotificationProvider
    {
        return NotificationProvider::Apifon;
    }

    /**
     * Digits only, with the leading `+` removed.
     *
     * Apifon wants a bare international number; the rest of the product stores
     * E.164 because `propaganistas/laravel-phone` produces it and because it is
     * the only format that is unambiguous. The conversion belongs here rather
     * than in the caller — the next provider wants it differently.
     */
    private function digitsOf(string $e164): string
    {
        return ltrim(preg_replace('/\D+/', '', $e164) ?? '', '0');
    }

    /** @param  mixed  $payload */
    private static function costOf($payload): ?int
    {
        $charge = is_array($payload) ? ($payload['total_cost'] ?? null) : null;

        return is_numeric($charge) ? (int) round(((float) $charge) * 100) : null;
    }
}
