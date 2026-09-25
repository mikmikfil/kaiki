<?php

declare(strict_types=1);

use App\Contracts\Channel;
use App\Domain\Channels\Channels\IcalChannel;
use App\Domain\Channels\Channels\NullChannel;
use App\Domain\Channels\Data\ChannelOutcome;
use App\Domain\Channels\Data\ChannelResult;
use App\Domain\Channels\Support\ChannelRegistry;
use App\Enums\ChannelKey;
use App\Models\Departure;
use App\Models\IcalSource;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| EXT-1's interface, and the two rules it exists to hold (ADR-0034)
|--------------------------------------------------------------------------
|
| The spec designed `App\Contracts\Channel` at M0 and nothing built it for
| seven milestones, so the first thing to prove is that the abstraction can
| express the channel that already existed — not just the OTA it was written
| for. `IcalChannel` is that proof, and its two refusals are the assertions
| worth having: an interface every implementation satisfies completely is an
| interface shaped by one caller.
|
| The second rule is the one the whole feature is for. A channel must not be
| able to make a seat movement fail, and must not be able to report success for
| work it did not do — because the nightly reconciliation believes it.
|
*/

/** @return array{0: Tenant, 1: IcalSource} */
function channelIcalFixture(bool $active = true): array
{
    $tenant = Tenant::factory()->create();

    $source = Tenancy::forTenant($tenant, function () use ($active): IcalSource {
        $vessel = Vessel::factory()->create();

        return IcalSource::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'url' => 'https://example.test/calendar.ics',
            'is_active' => $active,
        ]);
    });

    return [$tenant, $source];
}

function channelIcs(string $events = ''): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . $events . "END:VCALENDAR\r\n";
}

it('answers for a channel nobody registered, rather than throwing', function (): void {
    // The callers are a queued push after a seat moved and a nightly
    // reconciliation. Neither has any business exploding because the platform
    // has a feature switched off — and GetYourGuide is switched off until its
    // certification passes, which is its normal state for months.
    $registry = new ChannelRegistry;

    $channel = $registry->for(ChannelKey::GetYourGuide);

    expect($channel)->toBeInstanceOf(NullChannel::class)
        ->and($channel->key())->toBe(ChannelKey::GetYourGuide)
        ->and($registry->has(ChannelKey::GetYourGuide))->toBeFalse();
})->group('fast');

it('declines every call when the channel is not connected, visibly and without retrying', function (): void {
    $channel = new NullChannel(ChannelKey::GetYourGuide);
    $tenant = Tenant::factory()->create();

    $result = $channel->pullBookings($tenant);

    expect($result->outcome)->toBe(ChannelOutcome::NotConfigured)
        // Visible: an operator should learn on day one that nothing is sent.
        ->and($result->needsAttention())->toBeTrue()
        // But not retryable — a credential does not appear because we asked
        // twice, and eight attempts would bury the one line worth reading.
        ->and($result->isRetryable())->toBeFalse()
        ->and($result->succeeded())->toBeFalse();
})->group('fast');

it('never reports an unconnected channel as done, because the reconciliation believes it', function (): void {
    // The trap this guards. If "not connected" read as success, the nightly job
    // whose entire purpose is to notice a disagreement about what is sold would
    // be the thing guaranteeing there is none.
    $channel = new NullChannel(ChannelKey::GetYourGuide);
    $tenant = Tenant::factory()->create();

    expect($channel->pullBookings($tenant)->succeeded())->toBeFalse();
})->group('fast');

it('registers iCal, so the contract has a second implementation shaping it', function (): void {
    $registry = app(ChannelRegistry::class);

    expect($registry->has(ChannelKey::Ical))->toBeTrue()
        ->and($registry->for(ChannelKey::Ical))->toBeInstanceOf(IcalChannel::class);
})->group('fast');

it('refuses to push availability to a calendar, and says it is not applicable rather than failing', function (): void {
    // An iCal feed is fetched *from* Kaiki on the reader's own schedule. There
    // is nobody to tell. Three things must be true at once: it is not success,
    // it is not retried, and it never reaches the operator's failure feed —
    // otherwise every operator with a calendar collects a daily error that
    // means nothing.
    $channel = app(IcalChannel::class);
    $departure = new Departure;

    $result = $channel->pushAvailability($departure);

    expect($result->outcome)->toBe(ChannelOutcome::NotApplicable)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->isRetryable())->toBeFalse()
        ->and($result->needsAttention())->toBeFalse()
        ->and($result->providerDetail)->toContain('fetched from Kaiki');
})->group('fast');

it('has no products, because a calendar event says a hull is out and never which trip was sold', function (): void {
    [$tenant] = channelIcalFixture();

    expect(app(IcalChannel::class)->productFor($tenant, 'anything-at-all'))->toBeNull();
})->group('fast');

it('pulls every active source for one operator and reports what moved', function (): void {
    Http::fake(fn () => Http::response(channelIcs(
        "BEGIN:VEVENT\r\nUID:charter-1\r\nDTSTAMP:20260901T000000Z\r\n" .
        "DTSTART:20261001T080000Z\r\nDTEND:20261001T180000Z\r\nSUMMARY:Booked\r\nEND:VEVENT\r\n"
    )));

    [$tenant] = channelIcalFixture();

    $result = app(IcalChannel::class)->pullBookings($tenant);

    expect($result->succeeded())->toBeTrue()
        // One block created. The count is what a reconciliation reads to know
        // whether anything actually changed.
        ->and($result->affected)->toBe(1);
})->group('fast');

it('leaves an inactive source alone', function (): void {
    Http::fake();

    [$tenant] = channelIcalFixture(active: false);

    $result = app(IcalChannel::class)->pullBookings($tenant);

    expect($result->succeeded())->toBeTrue()
        ->and($result->affected)->toBe(0);

    Http::assertNothingSent();
})->group('fast');

it('reports a calendar it could not read as a retryable failure, not as an empty calendar', function (): void {
    // The asymmetry `SyncIcalSource` is built on, carried up to the channel: an
    // unread feed and an empty one are the same bytes to a naive reader, and
    // treating the first as the second would delete every block the source made
    // and put a boat back on sale that is already out.
    Http::fake(fn () => Http::response('', 500));

    [$tenant] = channelIcalFixture();

    $result = app(IcalChannel::class)->pullBookings($tenant);

    expect($result->succeeded())->toBeFalse()
        ->and($result->isRetryable())->toBeTrue()
        ->and($result->needsAttention())->toBeTrue()
        ->and($result->messageKey)->toBe('channels.ical.sources_failed')
        ->and($result->messageArguments)->toBe(['count' => 1]);
})->group('fast');

it('keeps going after one source fails, because calendars fail independently', function (): void {
    // One host down must not leave the other boat's occupancy unknown.
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        return $calls === 1
            ? Http::response('', 500)
            : Http::response(channelIcs());
    });

    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        foreach (['https://a.test/c.ics', 'https://b.test/c.ics'] as $url) {
            IcalSource::factory()->create([
                'vessel_id' => Vessel::factory()->create()->getKey(),
                'url' => $url,
                'is_active' => true,
            ]);
        }
    });

    app(IcalChannel::class)->pullBookings($tenant);

    expect($calls)->toBe(2);
})->group('fast');

it('records which channels are live, so the gap stays visible in the suite', function (): void {
    // The same job `NoPersonalDataInAuditContextTest` does for `AuditAction`.
    // When GetYourGuide is wired, this list changes and somebody reads the diff.
    $registered = array_map(
        fn (ChannelKey $key): string => $key->value,
        app(ChannelRegistry::class)->registered(),
    );

    expect($registered)->toBe(['ical']);
})->group('fast');

it('knows which channels the platform switch governs, and which it does not', function (): void {
    // iCal predates the flag and operators depend on it; switching it off would
    // remove a feature they have. The OTAs are off until certification passes.
    expect(ChannelKey::Ical->needsChannelManagerFlag())->toBeFalse()
        ->and(ChannelKey::GetYourGuide->needsChannelManagerFlag())->toBeTrue()
        ->and(ChannelKey::GetYourGuide->isInbound())->toBeTrue()
        ->and(ChannelKey::Ical->isInbound())->toBeFalse();
})->group('fast');

it('binds every channel to the contract', function (): void {
    foreach (app(ChannelRegistry::class)->registered() as $key) {
        expect(app(ChannelRegistry::class)->for($key))->toBeInstanceOf(Channel::class);
    }
})->group('fast');

it('counts only an outright failure as retryable', function (): void {
    expect(ChannelResult::done()->isRetryable())->toBeFalse()
        ->and(ChannelResult::notApplicable('because')->isRetryable())->toBeFalse()
        ->and(ChannelResult::notConfigured()->isRetryable())->toBeFalse()
        ->and(ChannelResult::failed('channels.result.not_configured')->isRetryable())->toBeTrue();
})->group('fast');
