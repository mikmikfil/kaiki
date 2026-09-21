<?php

declare(strict_types=1);

use App\Domain\Channels\Support\ChannelManagerFlag;
use App\Models\User;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| EXT-2's first wired flag, and the two ways it could go quietly wrong
|--------------------------------------------------------------------------
|
| ADR-0034 ships the GetYourGuide code months before GetYourGuide certifies it.
| For that whole window the only thing standing between written code and seats
| sold under a contract nobody signed is this switch being shut — so "shut by
| default" is not a preference, it is the safety property, and it is asserted
| here rather than assumed.
|
| The second failure is subtler and is the one Pennant invites: its default
| scope is the authenticated user. A feature left unscoped answers per person,
| so "has GetYourGuide certified us?" would depend on who was looking, and a
| queued job with nobody logged in would get a third answer again.
|
*/

it('is shut on a database that has never been touched', function (): void {
    // A fresh install, a new deployment and a restored backup all land here.
    expect(ChannelManagerFlag::isOpen())->toBeFalse();
})->group('fast');

it('opens only when somebody opens it, and closes again', function (): void {
    ChannelManagerFlag::open();
    expect(ChannelManagerFlag::isOpen())->toBeTrue();

    ChannelManagerFlag::close();
    expect(ChannelManagerFlag::isOpen())->toBeFalse();
})->group('fast');

it('gives the same answer whoever is logged in, and when nobody is', function (): void {
    // The Pennant trap. Without the pinned null scope this stores one row per
    // user, and the certification question gets a per-person answer.
    ChannelManagerFlag::open();

    $first = User::factory()->create();
    $second = User::factory()->create();

    actingAs($first);
    expect(ChannelManagerFlag::isOpen())->toBeTrue();

    actingAs($second);
    expect(ChannelManagerFlag::isOpen())->toBeTrue();

    auth()->logout();
    expect(ChannelManagerFlag::isOpen())->toBeTrue();
})->group('fast');

it('keeps one row for everybody, not one per person who asked', function (): void {
    ChannelManagerFlag::open();

    actingAs(User::factory()->create());
    ChannelManagerFlag::isOpen();

    actingAs(User::factory()->create());
    ChannelManagerFlag::isOpen();

    expect(DB::table('features')->where('name', ChannelManagerFlag::NAME)->count())->toBe(1);
})->group('fast');

it('forgets back to the shipped default rather than to an explicit no', function (): void {
    ChannelManagerFlag::open();
    ChannelManagerFlag::forget();

    // Asserted before the first read, because reading is what puts it back:
    // Pennant resolves the definition and stores the answer, so a row exists
    // again the moment anybody asks. The distinction being tested is that what
    // comes back is the *definition's* false and not a remembered one.
    expect(DB::table('features')->where('name', ChannelManagerFlag::NAME)->count())->toBe(0)
        ->and(ChannelManagerFlag::isOpen())->toBeFalse();
})->group('fast');

it('is the name the spec uses, so EXT-2 and the database agree', function (): void {
    expect(ChannelManagerFlag::NAME)->toBe('channel_manager')
        ->and(Feature::defined())->toContain('channel_manager');
})->group('fast');

it('reports its state without changing it', function (): void {
    artisan('channels:manager')
        ->expectsOutputToContain('CLOSED')
        ->assertSuccessful();

    expect(ChannelManagerFlag::isOpen())->toBeFalse();
})->group('fast');

it('refuses to open when the certification question is answered no', function (): void {
    // A runbook pasted by somebody who does not know the answer must not open
    // this. So the confirmation asks the actual question, and no means no.
    artisan('channels:manager open')
        ->expectsConfirmation('Has that certification passed?', 'no')
        ->assertSuccessful();

    expect(ChannelManagerFlag::isOpen())->toBeFalse();
})->group('fast');

it('opens on a yes, and says what is still owed', function (): void {
    artisan('channels:manager open')
        ->expectsConfirmation('Has that certification passed?', 'yes')
        // The manual still promises operators that Kaiki will not do this, and
        // it ships in the PDF they are handed. Saying so at the moment the
        // switch opens is the only reliable time anybody will read it.
        ->expectsOutputToContain('chapter-whats-coming.html')
        ->assertSuccessful();

    expect(ChannelManagerFlag::isOpen())->toBeTrue();
})->group('fast');

it('closes without asking, because shutting something off is never the risky direction', function (): void {
    ChannelManagerFlag::open();

    artisan('channels:manager close')->assertSuccessful();

    expect(ChannelManagerFlag::isOpen())->toBeFalse();
})->group('fast');
