<?php

declare(strict_types=1);

use App\Domain\Integrations\Actions\SaveIntegrationCredential;
use App\Domain\Integrations\Data\IntegrationCredentialData;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Exceptions\IntegrationCredentialIncomplete;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Support\Tenancy;
use Tests\Support\OperatorUser;
use Tests\Support\Secrets\CredentialLeakScanner;

/*
 * Spec SEC-9, MYD-15, PAY-3.
 *
 * Both requirements say a credential is never logged. Both are sentences, and a
 * sentence has never stopped anybody adding a `Log::debug` while chasing a
 * failing checkout at eleven at night. This gate turns the rule into a test
 * that fails on the shape rather than on the consequence — the same device as
 * `NoHardcodedVatRateTest`, for a requirement with worse consequences.
 */

/** @return list<string> */
function credentialScannedPaths(): array
{
    return [
        'app',
        'config',
        'database',
        'routes',
        'resources/views',
    ];
}

it('has no code anywhere that could log or render a credential', function (): void {
    $findings = CredentialLeakScanner::scan(credentialScannedPaths());

    $report = array_map(
        static fn (array $f): string => "{$f['file']}:{$f['line']} — {$f['snippet']} ({$f['why']})",
        $findings,
    );

    expect($report)->toBe([], sprintf(
        "Operator credentials reachable from a log line or a screen:\n%s\n\n" .
        'SEC-9 and MYD-15 both say a credential is never logged. Read the two encrypted columns only ' .
        'where they are used to make a call, and never pass either to a log, an exception, a form ' .
        'default or a Blade echo.',
        implode("\n", $report),
    ));
})->group('fast');

it('can actually detect every shape it claims to', function (): void {
    // Pointed at a fixture that is permanently wrong, so this fails the day the
    // scanner stops working rather than the day somebody notices.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/Support/Secrets/Fixtures/LeakedCredentials.php',
    );

    $reasons = implode(' ', array_map(
        static fn (array $f): string => $f['why'],
        CredentialLeakScanner::findingsIn($source),
    ));

    expect($reasons)->toContain('sink')
        ->and($reasons)->toContain('rendered')
        ->and($reasons)->toContain('revealable');
})->group('fast');

it('does not fire on the shapes that are legitimate', function (): void {
    // The other half, and the half that decides whether anyone keeps the lint.
    // A cast list, a `$hidden` array, a redaction and a log line carrying the
    // *public* half are all correct code that a blunter scanner would flag.
    $innocent = <<<'PHP'
    <?php
    class Ordinary
    {
        protected $hidden = ['credentials', 'webhook_secret'];

        public function casts(): array
        {
            return ['credentials' => 'encrypted:array', 'webhook_secret' => 'encrypted'];
        }

        public function redacts(array $attributes): array
        {
            $attributes['credentials'] = '[redacted]';

            return $attributes;
        }

        public function logsTheSafeHalf($credential): void
        {
            Log::info('integration.saved', ['config' => $credential->public_config]);
        }

        public function usesThemForTheirPurpose($credential, $client): mixed
        {
            return $client->withToken($credential->credentials['secret_key'])->get('/');
        }
    }
    PHP;

    expect(CredentialLeakScanner::findingsIn($innocent))->toBe([]);
})->group('fast');

it('points at directories that exist, so it cannot pass by scanning nothing', function (): void {
    $missing = array_values(array_filter(
        credentialScannedPaths(),
        static fn (string $path): bool => ! is_dir(base_path($path)),
    ));

    expect($missing)->toBe([]);
})->group('fast');

it('throws a refusal that names the fields and never their values', function (): void {
    $exception = IntegrationCredentialIncomplete::missing(
        IntegrationProvider::Stripe,
        ['secret_key', 'publishable_key'],
    );

    // An exception is the single most likely thing to be logged or pasted into
    // an issue, so this is the one place the rule has to hold from inside the
    // error path — where nobody is looking.
    expect($exception->getMessage())->toContain(__('integrations.fields.secret_key'))
        ->and($exception->fields)->toBe(['secret_key', 'publishable_key'])
        ->and($exception->getMessage())->not->toContain('sk_')
        ->and($exception->getMessage())->not->toContain('pk_');
})->group('fast');

it('keeps a credential out of a real exception raised by the save path', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    $thrown = Tenancy::forTenant($tenant, function (): ?Throwable {
        try {
            app(SaveIntegrationCredential::class)(
                new IntegrationCredentialData(
                    provider: IntegrationProvider::Stripe,
                    environment: CredentialEnvironment::Test,
                    // One field present, one missing — so a naive message that
                    // echoed the submitted array would carry a real value.
                    credentials: ['secret_key' => 'sk_test_do_not_log_me'],
                ),
            );
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    });

    expect($thrown)->toBeInstanceOf(IntegrationCredentialIncomplete::class)
        ->and($thrown?->getMessage())->not->toContain('sk_test_do_not_log_me')
        ->and(json_encode($thrown?->getTrace() === null ? [] : ['fields' => $thrown->fields]))
        ->not->toContain('sk_test_do_not_log_me');
})->group('fast');

it('keeps secrets out of the model json a queue payload or Sentry event would carry', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    $credential = Tenancy::forTenant(
        $tenant,
        fn (): IntegrationCredential => IntegrationCredential::factory()->stripe()->create(),
    );

    $secret = $credential->credentials['secret_key'];
    $json = (string) json_encode($credential);

    expect($json)->not->toContain($secret)
        ->and($json)->not->toContain((string) $credential->webhook_secret);
})->group('fast');

it('shows an operator four characters and no more', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    $credential = Tenancy::forTenant(
        $tenant,
        fn (): IntegrationCredential => IntegrationCredential::factory()->stripe()->create(),
    );

    $secret = $credential->credentials['secret_key'];
    $hint = $credential->hint('secret_key');

    expect($hint)->toEndWith(substr($secret, -4))
        // The concession is four characters. A hint that grew to eight would
        // still pass a "does not contain the whole secret" assertion.
        ->and(mb_strlen($hint))->toBe(8)
        ->and($hint)->not->toContain(substr($secret, 0, 8));
})->group('fast');
