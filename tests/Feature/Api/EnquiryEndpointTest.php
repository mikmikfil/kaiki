<?php

declare(strict_types=1);

use App\Enums\ApiScope;
use App\Enums\BookingSource;
use App\Enums\EnquiryStatus;
use App\Events\EnquiryReceived;
use App\Http\Requests\Api\V1\EnquiryCreateRequest;
use App\Models\Enquiry;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\postJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| POST /api/v1/enquiries — spec BKG-28 (FIXED), BKG-29; docs/api.md §5
|--------------------------------------------------------------------------
|
| The most spam-exposed endpoint in the system, and the one place in the product
| that accepts unstructured text from a stranger.
|
| Two of the assertions here are about what the endpoint **does not** do.
| BKG-28: *"It is not a booking and never touches availability."* The natural
| implementation is one helpful line — we know the product and the preferred
| date, so let us check whether it is free — and it would put this endpoint on
| the hot path of the availability engine, turning a spam run into a load test.
| A query counter is the only way to assert the absence of a thing.
|
| The other is the honeypot, which §2.5 says is *"never persisted"*. That is
| asserted against the **schema**, not against a row: a stored honeypot value is
| a column nobody remembers the purpose of, and in three years somebody renders
| it on a screen.
|
*/

/** @return array{0: string, 1: string} tenant key and a product uuid */
function enquiryFixture(): array
{
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::BookingsWrite]);

    $uuid = Tenancy::forTenant($tenant, fn (): string => (string) Product::factory()->create()->uuid);

    return [$key, $uuid];
}

/**
 * A well-formed body.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function enquiryBody(array $overrides = []): array
{
    return array_replace([
        'name' => 'Γιώργος Νικολάου',
        'email' => 'giorgos@example.gr',
        'message' => 'Καλησπέρα, κάνετε ημερήσια εκδρομή στη Δήλο;',
    ], $overrides);
}

it('records an enquiry and answers with the thin contract shape', function (): void {
    Event::fake([EnquiryReceived::class]);

    [$key, $productUuid] = enquiryFixture();

    $response = postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(['product_uuid' => $productUuid, 'phone' => '+306912345678', 'pax' => 6]),
        ['Authorization' => "Bearer {$key}"],
    );

    $response->assertCreated()
        // `docs/api.md`: *"Deliberately thin. An enquiry is not a booking, has
        // no guest token, and is answered by email."* There is nothing here a
        // client could poll, because there is nothing to poll.
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.product_uuid', $productUuid)
        // §3.6 puts everything guest-specific here. Laravel adds `private`
        // alongside, which is the same instruction said twice.
        ->assertHeaderMissing('X-Kaiki-Cached');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // The guest's own message is not echoed back — it is in their form and
    // their sent mail, and returning it would put a stranger's words into a
    // response a widget on a shared page might log.
    expect($response->json('data'))->not->toHaveKey('message');

    // BKG-29: the operator is told **immediately**. An enquiry is a person
    // waiting for an answer.
    Event::assertDispatched(EnquiryReceived::class);
});

it('touches nothing in the availability engine', function (): void {
    [$key, $productUuid] = enquiryFixture();

    $tables = [];

    DB::listen(function ($query) use (&$tables): void {
        $tables[] = strtolower($query->sql);
    });

    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(['product_uuid' => $productUuid, 'preferred_date' => '2026-07-04', 'pax' => 6]),
        ['Authorization' => "Bearer {$key}"],
    )->assertCreated();

    $sql = implode(' ', $tables);

    // BKG-28's second clause, asserted the only way the absence of a thing can
    // be. An enquiry names a product and a date and still asks the engine
    // nothing — no departures, no blocks, no rate plans, no counters.
    foreach (['departures', 'vessel_blocks', 'rate_plans', 'schedule_rules'] as $table) {
        expect($sql)->not->toContain(" {$table} ", "the enquiry endpoint queried `{$table}`");
    }
});

it('never gives the honeypot a column to live in', function (): void {
    // §2.5: *"a honeypot field that is never persisted."* Asserted against the
    // schema rather than against a row, because the failure this guards against
    // is somebody adding the column later "for spam analysis" and somebody else
    // rendering it three years after that.
    expect(Schema::hasColumn('enquiries', 'company_website'))->toBeFalse();
});

it('refuses a filled honeypot, creating nothing and notifying nobody', function (): void {
    Event::fake([EnquiryReceived::class]);

    [$key] = enquiryFixture();

    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(['company_website' => 'https://cheap-pills.example']),
        ['Authorization' => "Bearer {$key}"],
    )->assertStatus(422);

    // Both halves of the contract's sentence. A row would make the operator's
    // inbox the spam target instead of the endpoint; a notification would make
    // it their phone.
    expect(Enquiry::query()->withoutGlobalScopes()->count())->toBe(0);

    Event::assertNotDispatched(EnquiryReceived::class);
});

it('refuses a submission faster than a person could type it', function (): void {
    [$key] = enquiryFixture();

    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(['form_rendered_at' => now()->toIso8601String()]),
        ['Authorization' => "Bearer {$key}"],
    )->assertStatus(422)
        // The same code and the same message as the honeypot: a client that
        // knows *which* check it failed knows which one to work around.
        ->assertJsonPath('error.code', 'enquiry_rejected');

    expect(Enquiry::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('accepts a legitimately slow human, which is the whole point of one bound', function (): void {
    [$key] = enquiryFixture();

    // Opened the form, was interrupted by a phone call, came back forty minutes
    // later. The issue's own note: this is **not** a bot, and an "implausibly
    // slow" rejection would refuse exactly the enquiries an operator most wants
    // — the careful ones.
    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(['form_rendered_at' => now()->subMinutes(40)->toIso8601String()]),
        ['Authorization' => "Bearer {$key}"],
    )->assertCreated();
});

it('accepts a request that sends no timestamp at all', function (): void {
    [$key] = enquiryFixture();

    // A WordPress shortcode from before the field existed is not an attacker.
    // A check that refused the absent case would break every older client on
    // the day it shipped.
    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(),
        ['Authorization' => "Bearer {$key}"],
    )->assertCreated();
});

it('accepts a clock-skewed browser rather than calling it a bot', function (): void {
    [$key] = enquiryFixture();

    // A future timestamp is a wrong system time, and refusing it would turn
    // that into an unfixable "your message could not be sent".
    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(['form_rendered_at' => now()->addHour()->toIso8601String()]),
        ['Authorization' => "Bearer {$key}"],
    )->assertCreated();
});

it('limits an IP to five a minute, the tightest number in the contract', function (): void {
    [$key] = enquiryFixture();

    // §3.6 class F: 120 per key and **5 per IP**, four times tighter than
    // anything else. The honeypot and the timing check are filters on top of
    // this number, not instead of it.
    for ($i = 0; $i < 5; $i++) {
        postJson(
            CatalogRequest::url('/enquiries'),
            enquiryBody(['message' => "Message number {$i}, long enough to pass validation."]),
            ['Authorization' => "Bearer {$key}"],
        )->assertCreated();
    }

    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(),
        ['Authorization' => "Bearer {$key}"],
    )->assertStatus(429);
});

it('refuses a key without the scope, before the controller loads', function (): void {
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::ProductsRead]);

    // `bookings.write`, which §2.1's table assigns. An enquiry is the call to
    // action on a `mode: quote` product, so a key that may start a booking is
    // the key that may ask a question — and an SEO-sync key is not.
    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(),
        ['Authorization' => "Bearer {$key}"],
    )->assertStatus(403);
});

it('ignores a product uuid belonging to another operator', function (): void {
    [$key] = enquiryFixture();

    $other = Tenancy::forTenant(
        Tenant::factory()->create(),
        fn (): string => (string) Product::factory()->create()->uuid,
    );

    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(['product_uuid' => $other]),
        ['Authorization' => "Bearer {$key}"],
    )->assertCreated()
        // Resolved to nothing rather than to a cross-tenant reference. The
        // enquiry is still recorded — a guest who pasted the wrong link still
        // asked a question — and it is simply a general one.
        ->assertJsonPath('data.product_uuid', null);
});

it('stores the guest words verbatim and records the source address', function (): void {
    [$key] = enquiryFixture();

    postJson(
        CatalogRequest::url('/enquiries'),
        enquiryBody(['message' => '  Καλησπέρα!  Κάνετε ΚΑΙ ιδιωτικές ναυλώσεις;  ']),
        ['Authorization' => "Bearer {$key}"],
    )->assertCreated();

    $enquiry = Enquiry::query()->withoutGlobalScopes()->sole();

    // `docs/api.md` §4: free guest text *"is stored and returned exactly as
    // written and is never translated"* — the interior spacing and the shouty
    // capitals are the guest's own and survive intact.
    //
    // The **outer** whitespace does not: Laravel's global `TrimStrings` runs on
    // every request in the application and is not this endpoint's to opt out
    // of. That is the one deviation from "exactly as written", it applies
    // identically to every other guest field in the product, and it changes
    // nothing anybody reads.
    expect($enquiry->message)->toBe('Καλησπέρα!  Κάνετε ΚΑΙ ιδιωτικές ναυλώσεις;')
        ->and($enquiry->status)->toBe(EnquiryStatus::New)
        // §2.5's own column default. The API cannot tell a widget mount from a
        // WordPress shortcode, and guessing from the referrer would be a guess
        // recorded as a fact.
        ->and($enquiry->source)->toBe(BookingSource::Widget)
        // Rate limiting and spam review (§2.5) — not marketing data.
        ->and($enquiry->ip_address)->not->toBeNull();
});

it('bounds the timing check at three seconds, stated in one place', function (): void {
    // A constant rather than config: a per-tenant knob here would be a
    // per-tenant way to switch the check off.
    expect(EnquiryCreateRequest::MINIMUM_SECONDS)->toBe(3);
});
