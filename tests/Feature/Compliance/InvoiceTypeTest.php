<?php

declare(strict_types=1);

use App\Domain\Compliance\Support\InvoiceTypeResolver;
use App\Domain\Compliance\Support\VatNumber;
use App\Enums\InvoiceType;
use App\Models\Booking;

/*
|--------------------------------------------------------------------------
| ΑΦΜ validation and the ΑΛΠ/ΤΠΥ decision — spec MYD-3.1, MYD-3.5, MYD-8
|--------------------------------------------------------------------------
|
| ADR-0003 asks for a **pure function of booking data, unit-testable, and
| explainable in the operator error feed**, and this file is what makes those
| three true. No database, no clock, no network: a booking in memory in, a type
| and a reason out.
|
| The rule that carries the file is MYD-3.5. A bad ΑΦΜ **never stops a sale**.
| It produces an ΑΛΠ — a correct document — plus a warning the operator can act
| on. The alternative is a ΤΠΥ that AADE refuses days later, discovered by the
| wrong person at the worst time.
|
*/

/** @param array<string, mixed> $attributes */
function bookingWith(array $attributes): Booking
{
    // Unsaved: the resolver reads attributes and touches nothing, which is the
    // property being asserted as much as the answers are.
    return new Booking($attributes);
}

it('accepts a real Greek ΑΦΜ and rejects the same number with one digit changed', function (): void {
    // The modulus-11 check exists to catch a typo, so the test that matters is
    // a typo rather than a random string.
    expect(VatNumber::isValidGreek('094014201'))->toBeTrue()
        ->and(VatNumber::isValidGreek('094014202'))->toBeFalse();
})->group('fast');

it('reads a number however a person typed it', function (): void {
    // «EL» is the VAT prefix and «GR» the country code; both turn up, and so do
    // spaces and dots. A form that accepts only the bare digits tells a paying
    // customer their own ΑΦΜ is wrong.
    foreach (['EL094014201', 'EL 094014201', 'GR094014201', '094.014.201', '094-014-201', ' 094014201 '] as $typed) {
        expect(VatNumber::isValid($typed))->toBeTrue("rejected «{$typed}»");
    }
})->group('fast');

it('refuses nine zeros, which satisfy the arithmetic and are not an ΑΦΜ', function (): void {
    expect(VatNumber::isValidGreek('000000000'))->toBeFalse();
})->group('fast');

it('refuses anything that is not nine digits', function (): void {
    foreach (['', '12345678', '1234567890', '09401420A', 'abcdefghi'] as $value) {
        expect(VatNumber::isValid($value))->toBeFalse("accepted «{$value}»");
    }
})->group('fast');

it('does not apply the Greek checksum to a foreign number', function (): void {
    // Every member state has its own scheme; reimplementing twenty-six from
    // memory is how a valid Italian number gets refused at a Greek checkout.
    expect(VatNumber::isValid('IT12345678901', 'IT'))->toBeTrue()
        ->and(VatNumber::isValid('094014202', 'DE'))->toBeTrue()
        // Shape is still checked, so an empty or absurd value is refused.
        ->and(VatNumber::isValid('', 'IT'))->toBeFalse()
        ->and(VatNumber::isValid('THIS-IS-NOT-A-VAT-NUMBER-AT-ALL', 'IT'))->toBeFalse();
})->group('fast');

it('issues a receipt when the guest gave no tax details, which is nearly every booking', function (): void {
    $booking = bookingWith(['guest_vat_number' => null, 'guest_company_name' => null]);

    expect(InvoiceTypeResolver::for($booking))->toBe(InvoiceType::Alp)
        ->and(InvoiceTypeResolver::explain($booking)['reason'])
        ->toBe(InvoiceTypeResolver::REASON_NO_TAX_DETAILS)
        // And it is not a warning: the ordinary case in a warning list buries
        // the two rows a month that are real.
        ->and(InvoiceTypeResolver::needsAttention($booking))->toBeFalse();
})->group('fast');

it('issues an invoice when the number validates and a legal name is present', function (): void {
    $booking = bookingWith([
        'guest_vat_number' => '094014201',
        'guest_company_name' => 'Παράδειγμα ΑΕ',
        'guest_country' => 'GR',
    ]);

    expect(InvoiceTypeResolver::for($booking))->toBe(InvoiceType::Tpy)
        ->and(InvoiceTypeResolver::needsAttention($booking))->toBeFalse();
})->group('fast');

it('falls back to a receipt and raises a warning when the ΑΦΜ is mistyped', function (): void {
    // MYD-3.5, the rule this whole file exists for.
    $booking = bookingWith([
        'guest_vat_number' => '094014202',
        'guest_company_name' => 'Παράδειγμα ΑΕ',
        'guest_country' => 'GR',
    ]);

    expect(InvoiceTypeResolver::for($booking))->toBe(InvoiceType::Alp)
        ->and(InvoiceTypeResolver::explain($booking)['reason'])
        ->toBe(InvoiceTypeResolver::REASON_INVALID_VAT_NUMBER)
        ->and(InvoiceTypeResolver::needsAttention($booking))->toBeTrue();
})->group('fast');

it('falls back to a receipt when there is a number and no legal name', function (): void {
    // A number AADE cannot attach to a counterparty. Worth a warning, because
    // the guest clearly meant to ask for an invoice.
    $booking = bookingWith([
        'guest_vat_number' => '094014201',
        'guest_company_name' => '   ',
        'guest_country' => 'GR',
    ]);

    expect(InvoiceTypeResolver::for($booking))->toBe(InvoiceType::Alp)
        ->and(InvoiceTypeResolver::explain($booking)['reason'])
        ->toBe(InvoiceTypeResolver::REASON_MISSING_LEGAL_NAME)
        ->and(InvoiceTypeResolver::needsAttention($booking))->toBeTrue();
})->group('fast');

it('treats a name with no number as a private customer, not a problem', function (): void {
    // Somebody typed their employer into the wrong box. There is nothing to put
    // in a counterparty block and nothing for the operator to fix.
    $booking = bookingWith(['guest_vat_number' => '', 'guest_company_name' => 'Παράδειγμα ΑΕ']);

    expect(InvoiceTypeResolver::for($booking))->toBe(InvoiceType::Alp)
        ->and(InvoiceTypeResolver::needsAttention($booking))->toBeFalse();
})->group('fast');

it('assumes Greece when no country was given', function (): void {
    // A Greek operator's guest who left the country box alone is Greek, and the
    // checksum is the more useful default. Without this, a mistyped Greek ΑΦΜ
    // would pass the foreign shape check and produce a ΤΠΥ AADE refuses.
    $booking = bookingWith([
        'guest_vat_number' => '094014202',
        'guest_company_name' => 'Παράδειγμα ΑΕ',
        'guest_country' => null,
    ]);

    expect(InvoiceTypeResolver::for($booking))->toBe(InvoiceType::Alp);
})->group('fast');

it('never derives a credit note from a booking', function (): void {
    // A credit note is raised against an existing invoice by the refund path,
    // not derived from a sale. A resolver that could return one would let a
    // refund be mistaken for a booking.
    foreach ([[], ['guest_vat_number' => '094014201', 'guest_company_name' => 'Α ΑΕ']] as $attributes) {
        expect(InvoiceTypeResolver::for(bookingWith($attributes)))->not->toBe(InvoiceType::Credit);
    }
})->group('fast');
