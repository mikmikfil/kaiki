<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeader;

use Symfony\Component\HttpFoundation\Response;

/*
 * `docs/api.md` §4.1: one envelope, every failure, every endpoint.
 *
 *   {"error": {"code": …, "message": …, "message_el": …, "details": {…}}}
 *
 * The failures that break this promise are the ones no controller ever sees —
 * a typo in a path, a wrong verb, a validation failure thrown before any of our
 * code runs. Laravel answers those with an HTML error page or its own
 * `{"message": …}` shape, and a widget that branches on `error.code` gets a
 * parse error instead of a reason. §4.1 says clients branch on `code` and never
 * on `message`; that is only safe if `code` is genuinely always there.
 */

function envelopeKey(): string
{
    $tenant = Tenant::factory()->create();

    return Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Envelope test',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    )->plainTextKey);
}

/**
 * Every key §4.1 requires, and nothing shaped like a Laravel default.
 *
 * @param  TestResponse<Response>  $response
 */
function assertEnvelope(TestResponse $response, int $status): void
{
    $response->assertStatus($status)
        ->assertHeader('content-type', 'application/json')
        ->assertJsonStructure(['error' => ['code', 'message', 'message_el']]);

    $body = (array) $response->json();

    expect($body)->toHaveKey('error');

    foreach (['code', 'message', 'message_el'] as $field) {
        expect($body['error'][$field])->toBeString();
        expect($body['error'][$field])->not->toBe('');
    }

    // Laravel's own shape is a top-level `message`. If that leaks through, the
    // envelope was not applied and a client sees two different shapes.
    expect($body)->not->toHaveKey('message');
}

it('returns the envelope for an unknown path under /api/v1', function (): void {
    assertEnvelope(
        withHeader('X-Kaiki-Key', envelopeKey())->getJson('/api/v1/no-such-thing'),
        404,
    );
})->group('fast', 'api-docs');

it('returns the envelope for an unknown path even with no key', function (): void {
    // The most common real case: someone pastes a URL wrong while setting up
    // and has not added their key yet.
    assertEnvelope(getJson('/api/v1/no-such-thing'), 404);
})->group('fast', 'api-docs');

it('returns the envelope for the wrong HTTP verb', function (): void {
    assertEnvelope(
        withHeader('X-Kaiki-Key', envelopeKey())->postJson('/api/v1/health'),
        405,
    );
})->group('fast', 'api-docs');

it('returns the envelope for a validation failure', function (): void {
    Route::middleware(['api.key', 'tenant'])->post('/api/v1/_test/validated', function (): void {
        request()->validate(['pax' => ['required', 'integer', 'min:1']]);
    });

    $response = withHeader('X-Kaiki-Key', envelopeKey())
        ->postJson('/api/v1/_test/validated', ['pax' => 0]);

    assertEnvelope($response, 422);

    $response->assertJsonPath('error.code', 'validation_failed');

    // §4.2's shape, and all three parts of it matter.
    //
    // The map is nested under `fields` rather than spread across `details`,
    // because `details` carries other keys on other codes — `max_days` on
    // `invalid_date_range`, `retry_after_seconds` on `rate_limited` — so a
    // client reading `details` as "the field errors" breaks on the first one.
    //
    // Each entry is an **object**, not a list of strings: `code` is the failing
    // rule, which is what a form branches on to highlight a field and choose its
    // own wording without parsing English.
    //
    // And `message_el` is per field. §4.1 promises both languages in *every*
    // error; a top-level pair sitting over English-only field messages keeps
    // that promise where nobody reads and breaks it where the guest does.
    //
    // #35 shipped a flat `details` of message-string lists. The drift gate reads
    // paths and security schemes, not error shapes, so nothing caught it until
    // #37 needed the per-field Greek.
    $fields = $response->json('error.details.fields');

    expect($fields)->toHaveKey('pax')
        ->and($fields['pax']['code'])->toBe('min')
        ->and($fields['pax']['message'])->toBeString()
        ->and($fields['pax']['message'])->not->toBeEmpty()
        ->and($fields['pax']['message_el'])->toBeString()
        ->and($fields['pax']['message_el'])->not->toBeEmpty()
        // Actually the other language, not the same string twice.
        ->and($fields['pax']['message_el'])->not->toBe($fields['pax']['message']);
})->group('fast', 'api-docs');

it('returns the envelope for an unauthenticated request', function (): void {
    assertEnvelope(getJson('/api/v1/health'), 401);
})->group('fast', 'api-docs');

it('leaves non-API 404s alone', function (): void {
    // The envelope is scoped to `/api/v1`. The panel and the hosted pages are
    // HTML and must keep rendering Laravel's error view.
    $response = get('/definitely-not-a-route');

    $response->assertNotFound();

    expect($response->headers->get('content-type'))->not->toContain('application/json');
})->group('fast', 'api-docs');

it('omits details entirely rather than sending null', function (): void {
    // §4.1: "Absent rather than `null` when empty." A client checking
    // `if (error.details)` and one checking `if ('details' in error)` must
    // agree, and a null splits them.
    $body = getJson('/api/v1/health')->json();

    expect($body['error'])->not->toHaveKey('details');
})->group('fast', 'api-docs');
