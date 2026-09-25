<?php

declare(strict_types=1);

use App\Domain\Channels\Channels\NullChannel;
use App\Domain\Channels\Support\ChannelManagerFlag;
use App\Domain\Channels\Support\ChannelResolver;
use App\Enums\ChannelKey;
use App\Enums\IntegrationProvider;
use App\Models\Tenant;

/*
|--------------------------------------------------------------------------
| Two locks, and neither one alone is enough (ADR-0034)
|--------------------------------------------------------------------------
|
| `channel_manager` says whether the integration may exist at all, and is shut
| until GetYourGuide certifies it. `tenants.getyourguide_enabled` says which
| operator may use it once it may. The whole value of having two is that one
| being wrong is not enough to sell a seat, so each of the four combinations is
| asserted rather than reasoned about.
|
| The subtler assertion is the last one: an operator who is *allowed* to set
| GetYourGuide up sees the card before the endpoints that use it are built.
| Refusing to show it until then would mean asking for credentials in the same
| release that first needs them, which is the wrong way round.
|
*/

function gygTenant(bool $enabled): Tenant
{
    return Tenant::factory()->create(['getyourguide_enabled' => $enabled]);
}

it('refuses when the platform has not opened the channel, however the merchant is set', function (): void {
    // The case that matters most: the code ships months before certification,
    // and a mis-click on Edit Merchant must not be able to start selling.
    $tenant = gygTenant(true);

    expect(ChannelManagerFlag::isOpen())->toBeFalse()
        ->and(app(ChannelResolver::class)->isPermittedFor($tenant, ChannelKey::GetYourGuide))->toBeFalse()
        ->and(app(ChannelResolver::class)->for($tenant, ChannelKey::GetYourGuide))->toBeInstanceOf(NullChannel::class);
})->group('fast');

it('refuses when the merchant has not been switched on, however the platform is set', function (): void {
    ChannelManagerFlag::open();

    $tenant = gygTenant(false);

    expect(app(ChannelResolver::class)->isPermittedFor($tenant, ChannelKey::GetYourGuide))->toBeFalse();
})->group('fast');

it('treats a merchant that predates the column as off', function (): void {
    // Null is off. An operator holds the GetYourGuide contract themselves, so
    // defaulting to on would offer their seats under an agreement nobody signed.
    ChannelManagerFlag::open();

    $tenant = Tenant::factory()->create(['getyourguide_enabled' => null]);

    expect($tenant->usesGetYourGuide())->toBeFalse()
        ->and(app(ChannelResolver::class)->isPermittedFor($tenant, ChannelKey::GetYourGuide))->toBeFalse();
})->group('fast');

it('permits only when both locks are open', function (): void {
    ChannelManagerFlag::open();

    $tenant = gygTenant(true);

    expect(app(ChannelResolver::class)->isPermittedFor($tenant, ChannelKey::GetYourGuide))->toBeTrue();
})->group('fast');

it('is permitted but not yet live, because the endpoints are a later issue', function (): void {
    // The honest in-between state. Permission is granted; nothing is built; so
    // `for()` still hands back a channel that declines rather than one that
    // pretends. When #135 registers the real channel this flips on its own.
    ChannelManagerFlag::open();

    $tenant = gygTenant(true);
    $resolver = app(ChannelResolver::class);

    expect($resolver->isPermittedFor($tenant, ChannelKey::GetYourGuide))->toBeTrue()
        ->and($resolver->isLiveFor($tenant, ChannelKey::GetYourGuide))->toBeFalse()
        ->and($resolver->for($tenant, ChannelKey::GetYourGuide))->toBeInstanceOf(NullChannel::class);
})->group('fast');

it('never gates an operator out of their own calendars', function (): void {
    // iCal predates both locks and operators depend on it today. The switch the
    // OTAs answer to must not be able to take it away.
    $tenant = gygTenant(false);

    expect(ChannelManagerFlag::isOpen())->toBeFalse()
        ->and(app(ChannelResolver::class)->isLiveFor($tenant, ChannelKey::Ical))->toBeTrue();
})->group('fast');

it('offers GetYourGuide on the connections screen only once both locks are open', function (): void {
    $off = gygTenant(false);
    $on = gygTenant(true);

    expect(IntegrationProvider::operatorOptions($on))->not->toHaveKey('getyourguide');

    ChannelManagerFlag::open();

    expect(IntegrationProvider::operatorOptions($on))->toHaveKey('getyourguide')
        ->and(IntegrationProvider::operatorOptions($off))->not->toHaveKey('getyourguide');
})->group('fast');

it('asks the operator for their supplier id and the key we call them with', function (): void {
    // The other half — the Basic username and password GetYourGuide uses to
    // call *us* — is deliberately absent until the endpoints it authenticates
    // exist. Handing somebody credentials for a URL that answers 404 is a
    // screen that lies.
    expect(IntegrationProvider::GetYourGuide->credentialFields())->toBe(['api_key'])
        ->and(IntegrationProvider::GetYourGuide->publicFields())->toBe(['supplier_id']);
})->group('fast');

it('keeps the supplier id readable and the key not', function (): void {
    // `publicFields` is the half an operator must be able to read back to check
    // against their GetYourGuide dashboard; `credentialFields` is never shown
    // again after saving.
    expect(IntegrationProvider::GetYourGuide->publicFields())->toContain('supplier_id')
        ->and(IntegrationProvider::GetYourGuide->credentialFields())->not->toContain('supplier_id');
})->group('fast');
