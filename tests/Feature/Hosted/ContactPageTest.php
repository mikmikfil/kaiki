<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\EnquiryStatus;
use App\Http\Requests\Api\V1\EnquiryCreateRequest;
use App\Models\Enquiry;
use App\Models\Product;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| `/{operator}/contact` — the contact page and its form (HOS-1, BKG-28, BKG-29)
|--------------------------------------------------------------------------
|
| The form writes an `enquiry`, not an email. That is the assertion this file
| exists for: a contact form that sent a message would put the operator's
| questions in an inbox, where nobody can say which were answered — while
| `enquiries` already has a status, a panel screen and BKG-29's immediate
| notification.
|
| The other two are BKG-29's cheap filters, and they are asserted here because
| they are cheap: a honeypot and a timing check stop the scripts that POST to
| every form they find, and nobody who is trying. The rate limit is the real
| defence and it is on the route.
|
*/

/**
 * A form submission with everything filled in the way a person would.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function contactForm(array $overrides = []): array
{
    return array_replace([
        'name' => 'Γιώργος Αντωνίου',
        'email' => 'giorgos@example.gr',
        'phone' => '+306941234567',
        'message' => 'Καλησπέρα, θα θέλαμε να κλείσουμε για έξι άτομα τον Ιούλιο.',
        // Long enough ago to be a person reading a form. The timing check is
        // bounded at one end only — too fast is a signal, too slow is a life.
        'form_rendered_at' => Carbon::now()->subMinute()->toIso8601String(),
    ], $overrides);
}

it('renders the form and the ways to reach a person', function (): void {
    $tenant = OperatorPage::operator('aegean-blue');

    Tenancy::forTenant($tenant, static function () use ($tenant): void {
        $tenant->forceFill(['phone' => '+302104100200'])->save();
    });

    $response = get(HostedRequest::url('/aegean-blue/contact?lang=el'))->assertOk();

    $response->assertSee(__('hosted.contact.title'), escape: false)
        // The form, and both of BKG-29's filters in the markup where they can
        // actually catch something.
        ->assertSee('name="message"', escape: false)
        ->assertSee('name="company_website"', escape: false)
        ->assertSee('name="form_rendered_at"', escape: false)
        // And the direct routes, for the visitor who wants an answer now rather
        // than an email tomorrow.
        ->assertSee('+302104100200', escape: false)
        ->assertSee($tenant->email, escape: false);
})->group('fast');

it('records a message as an enquiry rather than sending an email', function (): void {
    $tenant = OperatorPage::operator('aegean-blue');

    post(HostedRequest::url('/aegean-blue/contact'), contactForm())
        ->assertRedirect(HostedRequest::url('/aegean-blue/contact?lang=el'))
        ->assertSessionHas('sent', true);

    Tenancy::forTenant($tenant, static function (): void {
        $enquiry = Enquiry::query()->sole();

        expect($enquiry->email)->toBe('giorgos@example.gr')
            // Verbatim — `docs/api.md` §4: free guest text is stored exactly as
            // written and never translated.
            ->and($enquiry->message)->toContain('έξι άτομα')
            ->and($enquiry->status)->toBe(EnquiryStatus::New)
            // `hosted`, and this is the reason `toData()` takes a source: the
            // API cannot tell a widget from a page, and this one can.
            ->and($enquiry->source)->toBe(BookingSource::Hosted)
            // A general question names no trip, which is the ordinary case
            // rather than a missing value.
            ->and($enquiry->product_id)->toBeNull();
    });
})->group('fast');

it('attaches the question to the trip the visitor came from', function (): void {
    $tenant = OperatorPage::operator('aegean-blue');

    $product = Tenancy::forTenant($tenant, static fn (): Product => Product::factory()->create());

    // The link in the trip page's «any questions» card carries the uuid, and
    // the form sends it back. The operator reads the question beside the trip it
    // is about rather than beside "is this available?" with nothing to go on.
    get(HostedRequest::url('/aegean-blue/contact?product=' . $product->uuid))
        ->assertOk()
        ->assertSee($product->uuid, escape: false);

    post(HostedRequest::url('/aegean-blue/contact'), contactForm(['product_uuid' => $product->uuid]))
        ->assertSessionHas('sent', true);

    Tenancy::forTenant($tenant, static function () use ($product): void {
        expect(Enquiry::query()->sole()->product_id)->toBe($product->getKey());
    });
})->group('fast');

it('refuses a filled honeypot and writes nothing', function (): void {
    $tenant = OperatorPage::operator('aegean-blue');

    // §2.5: "a filled value is answered with no row created and no notification
    // sent". Refused rather than accepted-and-discarded — accepting would leave
    // the bot believing it succeeded.
    post(HostedRequest::url('/aegean-blue/contact'), contactForm(['company_website' => 'https://spam.example']))
        ->assertSessionHasErrors('company_website');

    Tenancy::forTenant($tenant, static function (): void {
        expect(Enquiry::query()->count())->toBe(0);
    });
})->group('fast');

it('refuses a submission faster than a person can read the form', function (): void {
    $tenant = OperatorPage::operator('aegean-blue');

    post(HostedRequest::url('/aegean-blue/contact'), contactForm([
        'form_rendered_at' => Carbon::now()->subSeconds(EnquiryCreateRequest::MINIMUM_SECONDS - 1)->toIso8601String(),
    ]))->assertSessionHasErrors('message');

    Tenancy::forTenant($tenant, static function (): void {
        expect(Enquiry::query()->count())->toBe(0);
    });
})->group('fast');

it('accepts a submission that took a very long time', function (): void {
    $tenant = OperatorPage::operator('aegean-blue');

    // Bounded at one end only. A guest who opens the form, is interrupted by a
    // telephone call and submits forty minutes later is not a bot — and an
    // "implausibly slow" rejection would refuse the careful enquiries an
    // operator most wants.
    post(HostedRequest::url('/aegean-blue/contact'), contactForm([
        'form_rendered_at' => Carbon::now()->subHours(3)->toIso8601String(),
    ]))->assertSessionHas('sent', true);

    Tenancy::forTenant($tenant, static function (): void {
        expect(Enquiry::query()->count())->toBe(1);
    });
})->group('fast');

it('links to the contact page from every hosted page', function (): void {
    OperatorPage::operator('aegean-blue');

    // A page a visitor cannot find is a page the operator paid for and nobody
    // uses. The header carries it, and so does the footer — which is where
    // somebody who has read to the bottom of a trip page is standing.
    foreach (['/aegean-blue', '/aegean-blue/search', '/aegean-blue/legal'] as $path) {
        get(HostedRequest::url($path))
            ->assertOk()
            ->assertSee('/aegean-blue/contact', escape: false);
    }
})->group('fast');
