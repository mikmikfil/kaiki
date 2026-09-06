<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Actions;

use App\Domain\Integrations\Data\VerificationResult;
use App\Domain\Integrations\Support\CredentialRepository;
use App\Domain\Integrations\Support\VerifierRegistry;
use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Make a real call with a stored credential set and record what happened
 * (spec EXT-2, CNV-11, PAY-11).
 *
 * ## Why the operator needs this button at all
 *
 * Because the alternative place to discover a mistyped key is a guest's
 * checkout. An operator pastes credentials once, months before their first
 * booking, and nothing else in the product would ever exercise them until real
 * money was involved.
 *
 * ## Every outcome writes a row
 *
 * Success writes `verified_at` and clears `last_error`; failure writes
 * `last_error` and clears `verified_at`. Clearing the timestamp on failure is
 * the part that matters: credentials that worked in March and have since been
 * revoked must stop reading as verified the moment we find out, or
 * {@see IntegrationCredential::isUsable()} keeps saying yes to a checkout that
 * will fail.
 *
 * ## A verifier that throws is a failure, not a 500
 *
 * A gateway that times out, resolves to nothing, or returns HTML where JSON was
 * promised will throw from deep inside an HTTP client. The operator pressed a
 * button labelled "check my keys"; an error page is not an answer to that. The
 * throwable is caught, logged **without the credential**, and turned into the
 * same plain Greek sentence as any other failure.
 */
final class VerifyIntegrationCredential
{
    public function __construct(
        private readonly VerifierRegistry $registry,
        private readonly CredentialRepository $repository,
    ) {}

    public function __invoke(IntegrationCredential $credential): VerificationResult
    {
        $result = $this->run($credential);

        $credential->forceFill([
            'verified_at' => $result->succeeded ? now() : null,
            'last_error' => $result->storedError(),
        ])->save();

        $this->repository->forgetTenant($credential->tenant_id);

        return $result;
    }

    private function run(IntegrationCredential $credential): VerificationResult
    {
        if (! $credential->isComplete()) {
            return VerificationResult::failure('integrations.verify.incomplete');
        }

        $verifier = $this->registry->for($credential->provider);

        if ($verifier === null) {
            // Honest rather than optimistic. See VerifierRegistry: reporting
            // success for a provider with no client yet would write a
            // `verified_at` nothing has earned.
            return VerificationResult::failure('integrations.verify.unavailable', [
                'provider' => $credential->provider->label(),
            ]);
        }

        try {
            return $verifier->verify($credential);
        } catch (Throwable $exception) {
            // The exception class and the provider, and nothing else. A message
            // from an HTTP client routinely contains the request it made, and
            // the request it made is authenticated — MYD-15, SEC-9.
            Log::warning('integration.verification_failed', [
                'provider' => $credential->provider->value,
                'environment' => $credential->environment->value,
                'tenant_id' => $credential->tenant_id,
                'exception' => $exception::class,
            ]);

            return VerificationResult::failure('integrations.verify.unreachable', [
                'provider' => $credential->provider->label(),
            ]);
        }
    }
}
