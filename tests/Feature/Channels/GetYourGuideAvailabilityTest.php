<?php

declare(strict_types=1);

use App\Domain\Channels\Actions\GenerateInboundCredentials;
use App\Domain\Channels\Support\ChannelManagerFlag;
use App\Enums\ChannelKey;
use App\Enums\CredentialEnvironment;
use App\Enums\DepartureStatus;
use App\Enums\IntegrationProvider;
use App\Models\AgeBand;
use App\Models\ChannelProductMap;
use App\Models\Departure;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| The endpoint GetYourGuide calls, and the promise it has to keep
|--------------------------------------------------------------------------
|
| «να μην υπάρχει overlap μεταξύ των πλατφορμών». The way this file proves it
| is not by inspecting the controller but by **moving seats on the operator's
| own side and asking GetYourGuide's endpoint what it sees**. If the two ever
| disagree, the integration is worthless however green everything else is.
|
| The rest is the authentication, which is unusual here: a GetYourGuide request
| carries no supplier id, no signature and no Origin, so the Basic username is
| simultaneously the credential and the tenant resolution.
|
*/

/** @return array{0: Tenant, 1: Product, 2: Departure, 3: string, 4: string} */
function gygFixture(int $capacity = 12, int $seatsSold = 0, string $externalId = 'GYG-TRIP-1'): array
{
    ChannelManagerFlag::open();

    $tenant = Tenant::factory()->create(['getyourguide_enabled' => true, 'timezone' => 'Europe/Athens']);

    $credential = Tenancy::forTenant($tenant, fn (): IntegrationCredential => IntegrationCredential::factory()->create([
        'provider' => IntegrationProvider::GetYourGuide,
        'environment' => CredentialEnvironment::Live,
        'credentials' => ['api_key' => 'their-outbound-key'],
        'public_config' => ['supplier_id' => 'SUP-42'],
        'is_active' => true,
    ]));

    $pair = app(GenerateInboundCredentials::class)($credential);

    [$product, $departure] = Tenancy::forTenant($tenant, function () use ($capacity, $seatsSold, $externalId): array {
        $vessel = Vessel::factory()->create(['capacity_max' => 40]);

        $product = Product::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'max_pax' => $capacity,
            'min_pax' => 0,
        ]);

        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'min_lead_time_hours' => 0,
            'max_advance_days' => 365,
        ]);

        $departure = Departure::factory()
            ->at(gygDate(), '09:00')
            ->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $vessel->getKey(),
                'capacity' => $capacity,
                'min_pax' => 0,
                'seats_sold' => $seatsSold,
                'status' => DepartureStatus::Scheduled,
            ]);

        ChannelProductMap::create([
            'channel' => ChannelKey::GetYourGuide->value,
            'product_id' => $product->getKey(),
            'external_product_id' => $externalId,
        ]);

        return [$product->refresh(), $departure];
    });

    return [$tenant, $product, $departure, $pair->username, $pair->password];
}

function gygDate(): string
{
    return Carbon::now()->addDays(30)->toDateString();
}

/**
 * @param  array<string, string>  $query
 * @return TestResponse<JsonResponse>
 */
function gygGet(?string $user, ?string $pass, array $query = ['productId' => 'GYG-TRIP-1']): TestResponse
{
    $query += ['fromDateTime' => gygDate() . 'T00:00:00Z', 'toDateTime' => gygDate() . 'T23:59:59Z'];

    $headers = $user === null
        ? []
        : ['Authorization' => 'Basic ' . base64_encode($user . ':' . (string) $pass)];

    return getJson('/channels/getyourguide/1/get-availabilities?' . http_build_query($query), $headers);
}

it('challenges a request with no credentials at all', function (): void {
    gygFixture();

    $response = gygGet(null, null);

    // Without `WWW-Authenticate` a 401 is malformed for Basic, and a strict
    // client reads it as a transport fault — which is the difference between
    // GetYourGuide telling the supplier their password is wrong and
    // GetYourGuide retrying quietly for an hour.
    $response->assertStatus(401)->assertHeader('WWW-Authenticate', 'Basic realm="Kaiki channel"');
})->group('fast');

it('gives the same answer for an unknown username and a wrong password', function (): void {
    [, , , $user] = gygFixture();

    $unknown = gygGet('gyg_nobody_at_all_0000000000000000', 'whatever');
    $wrongPassword = gygGet($user, 'not-the-password');

    // Telling them apart tells whoever is guessing which half they got right,
    // and the username is the easier half.
    expect($unknown->status())->toBe(401)
        ->and($wrongPassword->status())->toBe(401)
        ->and($unknown->json('errorCode'))->toBe($wrongPassword->json('errorCode'));
})->group('fast');

it('answers a switched-off connection with a reason rather than a shrug', function (): void {
    [$tenant, , , $user, $pass] = gygFixture();

    Tenancy::forTenant($tenant, function (): void {
        IntegrationCredential::query()->update(['is_active' => false]);
    });

    // Not a security leak: whoever sent this already proved they hold the
    // password. Collapsing it into the 401 would answer the operator's support
    // ticket with silence instead of with "you turned it off".
    gygGet($user, $pass)
        ->assertStatus(403)
        ->assertJsonPath('errorCode', 'connection_inactive');
})->group('fast');

it('refuses once the platform closes the channel, even with a good password', function (): void {
    [, , , $user, $pass] = gygFixture();

    ChannelManagerFlag::close();

    gygGet($user, $pass)
        ->assertStatus(403)
        ->assertJsonPath('errorCode', 'channel_not_enabled');
})->group('fast');

it('refuses once the merchant is switched off, even with a good password', function (): void {
    [$tenant, , , $user, $pass] = gygFixture();

    $tenant->forceFill(['getyourguide_enabled' => false])->save();

    gygGet($user, $pass)->assertStatus(403);
})->group('fast');

it('says a product is not mapped rather than saying it is sold out', function (): void {
    [, , , $user, $pass] = gygFixture();

    // An empty list would have GetYourGuide ask again every few minutes for as
    // long as the integration lives, about a trip that will never be there.
    gygGet($user, $pass, ['productId' => 'GYG-SOMETHING-ELSE'])
        ->assertStatus(404)
        ->assertJsonPath('errorCode', 'PRODUCT_NOT_FOUND');
})->group('fast');

it('answers with their field names, and the seats we actually have', function (): void {
    [, , , $user, $pass] = gygFixture(capacity: 12, seatsSold: 2);

    $response = gygGet($user, $pass)->assertSuccessful();

    $response->assertJsonPath('data.availabilities.0.productId', 'GYG-TRIP-1')
        ->assertJsonPath('data.availabilities.0.vacancies', 10);

    // Their `dateTime` is the departure's own local wall time with its offset.
    // In UTC a guest choosing «the 09:00 boat» is shown a different hour, and
    // the offset is the only thing that keeps the two agreeing across a clock
    // change.
    expect($response->json('data.availabilities.0.dateTime'))->toStartWith(gygDate() . 'T09:00:00');
})->group('fast');

it('drops a seat from GetYourGuide the moment one is sold on the operator own page', function (): void {
    // **The assertion the whole integration exists for.** No sync, no job, no
    // waiting: the endpoint reads the same `departures` row the operator's own
    // checkout writes.
    [$tenant, , $departure, $user, $pass] = gygFixture(capacity: 12, seatsSold: 2);

    expect(gygGet($user, $pass)->json('data.availabilities.0.vacancies'))->toBe(10);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $departure->forceFill(['seats_sold' => 9])->save();
    });

    expect(gygGet($user, $pass)->json('data.availabilities.0.vacancies'))->toBe(3);
})->group('fast');

it('hides a held seat from GetYourGuide while a guest is still paying for it', function (): void {
    // A hold is not a sale, and it still has to be invisible to the OTA — that
    // window is precisely when two platforms would otherwise sell the same seat.
    [$tenant, , $departure, $user, $pass] = gygFixture(capacity: 12, seatsSold: 0);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $departure->forceFill(['seats_held' => 4])->save();
    });

    expect(gygGet($user, $pass)->json('data.availabilities.0.vacancies'))->toBe(8);
})->group('fast');

it('leaves a full departure out entirely rather than offering it with zero seats', function (): void {
    // `vacancies` is `≥ 0` in their schema, so a zero is legal — and it reads
    // as "sold out today", which their calendar draws differently from "not on
    // sale". Only what can actually be sold is listed.
    [, , , $user, $pass] = gygFixture(capacity: 12, seatsSold: 12);

    gygGet($user, $pass)
        ->assertSuccessful()
        ->assertJsonPath('data.availabilities', []);
})->group('fast');

it('refuses a request that is missing the dates', function (): void {
    [, , , $user, $pass] = gygFixture();

    // A client error, not a 500: their retry ladder treats a 5xx as ours to fix
    // and keeps trying against a request that will never work.
    getJson(
        '/channels/getyourguide/1/get-availabilities?productId=GYG-TRIP-1',
        ['Authorization' => 'Basic ' . base64_encode($user . ':' . $pass)],
    )->assertStatus(400);
})->group('fast');

it('cannot reach another operator trips with its own credentials', function (): void {
    [, , , $user, $pass] = gygFixture(externalId: 'GYG-TRIP-1');

    // A second operator, mapping the *same* GetYourGuide product id — which is
    // possible, because those ids are unique on their side and not on ours.
    gygFixture(externalId: 'GYG-TRIP-1');

    $response = gygGet($user, $pass);

    // One answer, and it must be the first operator's. The tenant came from the
    // username before any controller ran, so the lookup is already scoped.
    $response->assertSuccessful();
    expect($response->json('data.availabilities'))->toHaveCount(1);
})->group('fast');

it('never writes the credential into a log line', function (): void {
    // The username is enough to mount an offline guess against, so neither half
    // is loggable. Asserted by reading the middleware rather than by capturing
    // output, which would only catch the paths a test happens to walk.
    $source = file_get_contents(app_path('Http/Middleware/AuthenticateChannel.php'));

    expect($source)->not->toContain('Log::')
        ->and($source)->not->toContain('logger(');
})->group('fast');
