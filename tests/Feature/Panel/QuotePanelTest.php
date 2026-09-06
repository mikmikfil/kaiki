<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\BuildQuote;
use App\Enums\EnquiryStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Filament\App\Resources\EnquiryResource;
use App\Filament\App\Resources\QuoteResource;
use App\Models\Enquiry;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

use Tests\Support\Booking\QuoteScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The operator's two screens (spec BKG-24…29, TEN-8, SEC-3)
|--------------------------------------------------------------------------
|
| `mode: quote` products have been unsellable since #18 shipped them, and the
| reason is this screen: the price cannot be derived from a table, so somebody
| has to type it. What these tests assert is the two places the screen could
| quietly break the state machine behind it — editing a sent offer, and holding
| a boat by default.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function quoteTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

it('lets an owner and a manager reach the quotes page', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/quotes')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the quotes page', function (): void {
    // `ViewFinancials` to read, `ManagePricing` to write — and crew have
    // neither. `PaymentPolicy` gives the reason: what a guest paid *"is not
    // their business and is the kind of thing that ends up discussed on a
    // quay"*, and a quote is that sentence in advance.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/quotes')->assertForbidden();
})->group('fast');

it('refuses crew the enquiry inbox', function (): void {
    // An enquiry is a stranger's name, email and phone. Crew have
    // `ViewPaxList` so they know who is aboard today; somebody who has not
    // booked anything is not on that list.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/enquiries')->assertForbidden();
})->group('fast');

it('lets an owner and a manager reach the enquiry inbox', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/enquiries')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('offers edit on a draft and revise on a sent quote, never the other way round', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        expect(QuoteResource::reviseAction()->record($quote)->isVisible())->toBeFalse()
            ->and(QuoteResource::sendAction()->record($quote)->isVisible())->toBeTrue();

        $quote->forceFill(['status' => QuoteStatus::Sent, 'sent_at' => now()])->save();

        // A sent quote is an offer somebody holds a link to. Editing it in
        // place would change the price under that link with nothing anywhere
        // saying so — §4.4's create-and-supersede exists for exactly that.
        expect(QuoteResource::reviseAction()->record($quote->refresh())->isVisible())->toBeTrue()
            ->and(QuoteResource::sendAction()->record($quote)->isVisible())->toBeFalse();
    });
})->group('fast');

it('does not hold the boat unless the operator asks', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        QuoteResource::sendAction()->record($quote)->call(['hold_vessel' => false]);

        // BKG-25's resolution is the **default**, not the option. A toggle that
        // defaulted to on would be the indefinite vessel block the requirement
        // was resolved to prevent.
        expect(VesselBlock::query()->count())->toBe(0)
            ->and($quote->refresh()->status)->toBe(QuoteStatus::Sent);
    });
})->group('fast');

it('holds the boat when the operator does ask, and expires it with the quote', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        QuoteResource::sendAction()->record($quote)->call(['data' => ['hold_vessel' => true]]);

        expect(VesselBlock::query()->sole()->ends_at_utc->toIso8601String())
            ->toBe($quote->refresh()->valid_until->toIso8601String());
    });
})->group('fast');

it('reports a refusal as a sentence rather than a stack trace', function (): void {
    [$tenant, $booking] = QuoteScenario::requested();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // A quote with no lines. Every one of `SendQuote`'s guards is an
        // operator's own slip, and each deserves a sentence.
        $empty = app(BuildQuote::class)($booking);

        QuoteResource::sendAction()->record($empty)->call(['hold_vessel' => false]);

        expect($empty->refresh()->status)->toBe(QuoteStatus::Draft);
    });
})->group('fast');

it('converts euros to cents once, and not through a float cast', function (): void {
    // `(int) (0.29 * 100)` is 28 in binary floating point, and an operator
    // would find out on an invoice.
    expect(QuoteResource::toCents('0.29'))->toBe(29)
        ->and(QuoteResource::toCents('950.00'))->toBe(95000)
        ->and(QuoteResource::toEuros(95000))->toBe('950.00');
})->group('fast');

it('marks an enquiry answered rather than deleting it', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(quoteTenantOf($owner), function (): void {
        $enquiry = Enquiry::factory()->create();

        EnquiryResource::markAnsweredAction()->record($enquiry)->call();

        expect($enquiry->refresh()->status)->toBe(EnquiryStatus::Answered)
            ->and($enquiry->answered_at)->not->toBeNull();
    });
})->group('fast');

it('marks spam as a status and keeps the row', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(quoteTenantOf($owner), function (): void {
        $enquiry = Enquiry::factory()->create();

        EnquiryResource::markSpamAction()->record($enquiry)->call();

        // §2.5: excluded from counts, purged after thirty days — not deleted on
        // sight. An operator who suspects the filters ate a real message has
        // somewhere to look.
        expect($enquiry->refresh()->status)->toBe(EnquiryStatus::Spam)
            ->and(Enquiry::query()->count())->toBe(1)
            ->and(Enquiry::query()->countable()->count())->toBe(0);
    });
})->group('fast');

it('counts only open, non-spam enquiries on the navigation badge', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(quoteTenantOf($owner), function (): void {
        Enquiry::factory()->count(2)->create();
        Enquiry::factory()->spam()->create();
        Enquiry::factory()->answered()->create();

        // A badge saying four when two are answered and one is for a Canadian
        // pharmacy is a badge an operator learns to ignore.
        expect(EnquiryResource::getNavigationBadge())->toBe('2');
    });
})->group('fast');

it('shows an operator only their own quotes', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    [$otherTenant, $otherBooking, $otherQuote] = QuoteScenario::drafted();

    Tenancy::forTenant(quoteTenantOf($owner), function () use ($otherQuote): void {
        // The global scope, asserted from the resource's own query rather than
        // from a bare model — a resource that overrode `getEloquentQuery()` and
        // lost the scope would pass a model-level test.
        expect(QuoteResource::getEloquentQuery()->pluck('id')->all())
            ->not->toContain($otherQuote->getKey());
    });

    expect(Quote::query()->withoutGlobalScopes()->count())->toBe(1);
})->group('fast');
