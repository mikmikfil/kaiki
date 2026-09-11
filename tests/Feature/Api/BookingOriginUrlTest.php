<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\HostedHost;
use App\Models\Booking;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;

/*
|--------------------------------------------------------------------------
| `origin_url` on `POST /bookings` — the «back to the website» link (2026-09-11)
|--------------------------------------------------------------------------
|
| The widget sends the page the guest was on, and the checkout and booking
| pages link back to it. It is an `href` on a page that looks like the
| operator's, so it is held to `return_url`'s rule (SEC-7, `AllowedOrigin`) —
| but a value that fails the rule is **dropped**, never refused. The assertion
| that matters most here is that the booking is still created: a link we will
| not print is not a reason to lose somebody's seats.
|
*/

/**
 * @param  list<string>  $origins
 * @return TestResponse<JsonResponse>
 */
function createWithOriginUrl(?string $originUrl, array $origins = []): TestResponse
{
    $fixture = BookingApiScenario::bookable(allowedOrigins: $origins);

    $headers = [
        'Authorization' => "Bearer {$fixture['key']}",
        'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
    ];

    // A key that names origins is a key `ApiKeyCors` refuses without one — see
    // `CheckoutReturnUrlTest` for why the fixture sends it.
    if ($origins !== []) {
        $headers['Origin'] = $origins[0];
    }

    return postJson(
        '/api/v1/bookings',
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], ['origin_url' => $originUrl]),
        $headers,
    );
}

/** @param  TestResponse<JsonResponse>  $response */
function storedOriginUrl(TestResponse $response): ?string
{
    $uuid = (string) $response->json('data.uuid');

    /** @var string|null */
    return Tenancy::withoutTenancy(static fn (): mixed => Booking::query()
        ->withoutGlobalScopes()
        ->where('uuid', $uuid)
        ->value('origin_url'));
}

it('stores the page the guest was on when the key allows its origin', function (): void {
    $response = createWithOriginUrl(
        'https://aegean-blue.example/trips/sunset?utm_source=facebook',
        ['https://aegean-blue.example'],
    )->assertCreated();

    expect(storedOriginUrl($response))->toBe('https://aegean-blue.example/trips/sunset?utm_source=facebook');
})->group('fast');

it('stores any origin when the key names none, as CORS already does', function (): void {
    $response = createWithOriginUrl('https://somewhere.example/boats')->assertCreated();

    expect(storedOriginUrl($response))->toBe('https://somewhere.example/boats');
})->group('fast');

it('drops an origin the key does not allow, and still creates the booking', function (): void {
    $response = createWithOriginUrl(
        'https://phishing.example/aegean-blue',
        ['https://aegean-blue.example'],
    )->assertCreated();

    expect(storedOriginUrl($response))->toBeNull()
        // The booking is the point: seats held, token issued.
        ->and($response->json('data.manage_token'))->toBeString();
})->group('fast');

it('always allows a page Kaiki serves itself', function (): void {
    // The hosted trip page starts bookings too, and it is not on any key's list.
    $url = 'http://' . HostedHost::authority() . '/aegean-blue/sunset-cruise';

    $response = createWithOriginUrl($url, ['https://aegean-blue.example'])->assertCreated();

    expect(storedOriginUrl($response))->toBe($url);
})->group('fast');

it('drops a scheme that is not http or https, even when any origin is allowed', function (): void {
    // Rendered as an `href`: under an empty allow-list an origin comparison
    // alone would have let a non-web scheme through.
    $response = createWithOriginUrl('ftp://aegean-blue.example/brochure.pdf')->assertCreated();

    expect(storedOriginUrl($response))->toBeNull();
})->group('fast');

it('stores nothing when nothing is sent', function (): void {
    $response = createWithOriginUrl(null)->assertCreated();

    expect(storedOriginUrl($response))->toBeNull();
})->group('fast');

it('still refuses a value that is not a URL at all', function (): void {
    createWithOriginUrl('not a url')->assertUnprocessable();
})->group('fast');
