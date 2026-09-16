<?php

declare(strict_types=1);

use App\Domain\Integrations\Actions\SaveIntegrationCredential;
use App\Domain\Integrations\Data\IntegrationCredentialData;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\GatewayWebhookEvent;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| The operator's own webhook address (PAY-5, PAY-7)
|--------------------------------------------------------------------------
|
| Viva authenticates the *receiver*: it calls the webhook address and expects
| the account's verification key printed back. That call carries no source code,
| no merchant id and no signature — so a single shared address cannot answer it,
| because it has no way to know whose key to print.
|
| Hence an address per credential set, with an unguessable token in the path.
| Not the source code: four digits are walkable, and whoever can read a
| verification key off an answer can then forge that operator's webhooks.
|
| The second thing the address buys is that the *sender* stops choosing which
| operator a delivery is matched against. On the shared route that came from
| `EventData.SourceCode` — a field in a body written by whoever is posting.
*/

/** The scenario's credential, with an address of its own. */
function tokenedCredential(Tenant $tenant, string $token): IntegrationCredential
{
    return Tenancy::forTenant($tenant, static function () use ($token): IntegrationCredential {
        $credential = IntegrationCredential::query()
            ->where('provider', IntegrationProvider::Viva->value)
            ->firstOrFail();

        $credential->forceFill(['webhook_token' => $token])->save();

        return $credential;
    });
}

it('prints the verification key to whoever holds the address', function (): void {
    [$tenant] = WebhookScenario::make();
    tokenedCredential($tenant, $token = str_repeat('a', 40));

    $response = getJson("/webhooks/viva/{$token}")->assertOk();

    // Both spellings: Viva's samples show `key`, their webhook guide prints
    // `Key`, and a verification that fails on capitalisation is undiagnosable
    // from the outside.
    expect($response->json('Key'))->toBe(WebhookScenario::SECRET)
        ->and($response->json('key'))->toBe(WebhookScenario::SECRET);
})->group('fast');

it('answers an address nobody owns with 404 and nothing else', function (): void {
    WebhookScenario::make();

    $response = getJson('/webhooks/viva/' . str_repeat('b', 40))->assertStatus(404);

    // No key, and no hint that a neighbouring token would have worked.
    expect($response->json('Key'))->toBeNull()
        ->and($response->json('key'))->toBeNull();
})->group('fast');

it('fetches the key from the gateway when the operator never pasted one', function (): void {
    [$tenant] = WebhookScenario::make();
    $credential = tokenedCredential($tenant, $token = str_repeat('c', 40));

    Tenancy::forTenant($tenant, static function () use ($credential): void {
        $credential->forceFill(['webhook_secret' => null])->save();
    });

    Http::fake([
        '*/api/messages/config/token' => Http::response(['Key' => 'fetched_from_viva']),
    ]);

    getJson("/webhooks/viva/{$token}")
        ->assertOk()
        ->assertJson(['Key' => 'fetched_from_viva']);

    // Stored, because `verifyWebhook()` compares against the column and nothing
    // else — the fetch fills it rather than standing beside it.
    Tenancy::forTenant($tenant, static function (): void {
        expect(IntegrationCredential::query()->first()?->webhook_secret)->toBe('fetched_from_viva');
    });
})->group('fast');

it('says 502 rather than printing something false when the gateway refuses', function (): void {
    [$tenant] = WebhookScenario::make();
    $credential = tokenedCredential($tenant, $token = str_repeat('d', 40));

    Tenancy::forTenant($tenant, static function () use ($credential): void {
        $credential->forceFill(['webhook_secret' => null])->save();
    });

    Http::fake([
        '*/api/messages/config/token' => Http::response(['message' => 'no'], 403),
    ]);

    getJson("/webhooks/viva/{$token}")->assertStatus(502);
})->group('fast');

it('takes a delivery on the operator address and verifies it against that operator', function (): void {
    [$tenant] = WebhookScenario::make();
    tokenedCredential($tenant, $token = str_repeat('e', 40));

    $payload = WebhookScenario::gatewaySuccess('evt_addressed');

    postJson("/webhooks/viva/{$token}", $payload, WebhookScenario::verifiedHeaders($payload))
        ->assertOk()
        ->assertJson(['received' => true]);

    expect(GatewayWebhookEvent::query()->where('signature_valid', true)->count())->toBe(1);
})->group('fast');

it('refuses a delivery to the right address with the wrong key', function (): void {
    [$tenant] = WebhookScenario::make();
    tokenedCredential($tenant, $token = str_repeat('f', 40));

    $payload = WebhookScenario::gatewaySuccess('evt_wrong_key');

    postJson("/webhooks/viva/{$token}", $payload, ['X-Viva-Verification' => 'not_the_key'])
        ->assertStatus(400)
        ->assertJson(['received' => false]);
})->group('fast');

it('matches the operator in the address, not the one named in the body', function (): void {
    [$tenant] = WebhookScenario::make();
    tokenedCredential($tenant, $token = str_repeat('1', 40));

    // A payload naming somebody else's payment source. On the shared address
    // this is the field that decides which credential the key is compared
    // against — which is the sender choosing their own verifier. Here the
    // address has already decided, so the body cannot.
    $payload = WebhookScenario::gatewaySuccess('evt_spoofed', [
        'EventData' => ['SourceCode' => 'someone_elses_source'],
    ]);

    postJson("/webhooks/viva/{$token}", $payload, WebhookScenario::verifiedHeaders($payload))
        ->assertOk()
        ->assertJson(['received' => true]);
})->group('fast');

it('mints an address on the first save and keeps it on every later one', function (): void {
    $tenant = Tenant::factory()->create();

    $data = new IntegrationCredentialData(
        provider: IntegrationProvider::Viva,
        environment: CredentialEnvironment::Test,
        credentials: ['client_id' => 'cid', 'client_secret' => 'secret', 'merchant_id' => 'mid', 'api_key' => 'akey'],
        publicConfig: ['source_code' => '1234'],
        webhookSecret: null,
        isDefault: true,
        isActive: true,
    );

    Tenancy::forTenant($tenant, static function () use ($data): void {
        $first = app(SaveIntegrationCredential::class)($data);
        $token = $first->webhook_token;

        expect($token)->toBeString()->and(strlen((string) $token))->toBe(40);

        // The operator has already pasted this address into Viva's dashboard.
        // Rotating it on a later save would break it silently — Viva would keep
        // posting to an address that no longer resolves.
        $second = app(SaveIntegrationCredential::class)($data);

        expect($second->webhook_token)->toBe($token);
    });
})->group('fast');

it('accepts a Viva credential set with no webhook key, because the key is ours to fetch', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, static function (): void {
        // The whole point of the change: an operator supplies a client id, a
        // client secret and a source code — the three things Viva's own plugin
        // asks for — and the form no longer refuses for want of a value they
        // would have had to make an API call to find.
        $credential = app(SaveIntegrationCredential::class)(new IntegrationCredentialData(
            provider: IntegrationProvider::Viva,
            environment: CredentialEnvironment::Test,
            credentials: ['client_id' => 'cid', 'client_secret' => 'secret', 'merchant_id' => 'mid', 'api_key' => 'akey'],
            publicConfig: ['source_code' => '1234'],
            webhookSecret: null,
            isDefault: true,
            isActive: true,
        ));

        expect($credential->exists)->toBeTrue()
            ->and($credential->webhook_secret)->toBeNull();
    });
})->group('fast');
