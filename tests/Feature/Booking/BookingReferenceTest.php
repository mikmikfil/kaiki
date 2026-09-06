<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Tenant;
use App\Rules\BookingReferenceFormat;
use App\Support\Booking\BookingReference;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;

/*
 * Spec BKG-3, BKG-4, ADR-0007 Option A.
 *
 * The reference is the string an operator reads down a phone line on a windy
 * pier, which is the entire reason it has a restricted alphabet. The tests that
 * matter here are about what a *human* does to it, not about randomness.
 */

it('excludes every character a human misreads', function (): void {
    $alphabet = BookingReference::ALPHABET;

    foreach (['0', 'O', 'I', '1', 'L', 'U'] as $excluded) {
        expect($alphabet)->not->toContain($excluded);
    }

    // Thirty, not the 31 the spec asserts. `docs/spec.md` BKG-3 item 2 states
    // the rule ("digits and uppercase letters minus 0 O I 1 L U") and then a
    // count and a combination total that belong to a 31-symbol alphabet — 8
    // digits plus 22 letters is 30, and 30^5 is 24.3 million, not 28.6. The
    // rule is the specification; the arithmetic was a slip, and #80 corrected
    // it in the spec with the reason in CHANGELOG.md.
    expect(strlen($alphabet))->toBe(30)
        ->and(30 ** 5)->toBe(24_300_000);
})->group('fast');

it('generates the shape the spec describes', function (): void {
    for ($i = 0; $i < 50; $i++) {
        $reference = (string) BookingReference::generate();

        expect($reference)->toMatch('/^KAI-[' . preg_quote(BookingReference::ALPHABET, '/') . ']{5}$/')
            ->and(BookingReference::isValid($reference))->toBeTrue();
    }
})->group('fast');

it('accepts the widened form, because its own collision strategy produces one', function (): void {
    // ADR-0007 item 4 widens to six characters after five collisions. A
    // validator that rejected the result would refuse a reference the system
    // itself had issued — which is why the column is varchar(16) and not the
    // ADR's own char(9).
    $widened = (string) BookingReference::generate(6);

    expect(BookingReference::isValid($widened))->toBeTrue()
        ->and(strlen($widened))->toBe(10);
})->group('fast');

it('reads what a guest actually types', function (string $typed): void {
    // Lowercase, spaces, a missing hyphen, an em dash from a copied PDF.
    expect(BookingReference::normalise($typed))->toBe('KAI-7F3K2');
})->with([
    'KAI-7F3K2',
    'kai-7f3k2',
    'KAI7F3K2',
    '  kai 7f3k2  ',
    "KAI\u{2014}7F3K2",
    'kai_7f3k2',
])->group('fast');

it('does not resolve a misread character onto somebody else booking', function (): void {
    // A guest reading `0` as `O`. The confusable map turns the `O` back into a
    // `0` — which is not in the alphabet and therefore never generated — so the
    // lookup fails honestly instead of finding a different real booking.
    expect(BookingReference::isValid(BookingReference::normalise('KAI-O7F3K')))->toBeFalse()
        ->and(BookingReference::tryFrom('KAI-O7F3K'))->toBeNull();
})->group('fast');

it('refuses input that is not a reference at all', function (string $rubbish): void {
    expect(BookingReference::tryFrom($rubbish))->toBeNull();
})->with([
    '',
    'KAI-',
    'KAI-7F3',
    'KAI-7F3K2X9',
    'ABC-7F3K2',
    '7F3K2',
])->group('fast');

it('reports an invalid reference in the guest own language', function (): void {
    app()->setLocale('el');

    $validator = Validator::make(['reference' => 'nonsense'], ['reference' => [new BookingReferenceFormat]]);

    expect($validator->fails())->toBeTrue();

    $message = (string) $validator->errors()->first('reference');

    // From the lang file, in Greek, and carrying an example built from the
    // configured prefix rather than a hardcoded one.
    expect($message)->not->toBe('booking.reference.invalid')
        ->and($message)->toContain('KAI-7F3K2');
})->group('fast', 'i18n');

it('lets two tenants hold the same reference', function (): void {
    // BKG-3 item 3: uniqueness is per tenant, which is exactly why any
    // cross-tenant surface must show the operator alongside the reference.
    $reference = (string) BookingReference::generate();

    foreach ([Tenant::factory()->create(), Tenant::factory()->create()] as $tenant) {
        Tenancy::forTenant($tenant, function () use ($reference): void {
            Booking::factory()->create(['reference' => $reference]);
        });
    }

    expect(Booking::query()->withoutGlobalScopes()->where('reference', $reference)->count())->toBe(2);
})->group('fast');

it('refuses the same reference twice within one tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $reference = (string) BookingReference::generate();

    Tenancy::forTenant($tenant, function () use ($reference): void {
        Booking::factory()->create(['reference' => $reference]);

        // The unique index is the guarantee. `CreateBookingDraft` catches this
        // exact violation and regenerates rather than checking first — §2.5 is
        // explicit that a check-then-insert is a no-op under SQLite.
        expect(fn () => Booking::factory()->create(['reference' => $reference]))
            ->toThrow(QueryException::class);
    });
})->group('fast');
