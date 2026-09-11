<?php

declare(strict_types=1);

use App\Domain\Import\Exceptions\ImportFileUnreadable;
use App\Domain\Import\Parsing\BookingsCsvReader;
use App\Domain\Import\Parsing\WxrReader;
use App\Domain\Import\Support\SourceValues;

/*
|--------------------------------------------------------------------------
| SAA-13: reading what WooCommerce and YITH Booking export
|--------------------------------------------------------------------------
|
| The fixtures in tests/Fixtures/import are a small shop in the shape the two
| exports actually have: a WordPress WXR file with product categories, YITH
| person types and booking products (their costs PHP-serialised, as WordPress
| stores post meta), and YITH's bookings CSV with Greek names and «165,00 €».
|
*/

function importFixture(string $name): string
{
    return base_path('tests/Fixtures/import/' . $name);
}

it('reads categories, person types and products from a WordPress export', function (): void {
    $wxr = (new WxrReader)->read(importFixture('yith-products.xml'));

    expect(array_column($wxr['categories'], 'name'))->toBe(['Κρουαζιέρες', 'Ηλιοβασίλεμα'])
        ->and(array_column($wxr['people_types'], 'title'))->toBe(['Ενήλικας', 'Παιδί', 'Βρέφος'])
        ->and(array_column($wxr['products'], 'id'))->toBe(['1421', '1508', '1600', '1700']);

    $cruise = $wxr['products'][0];

    // The serialised person-type costs, unserialised into type id => cost.
    expect($cruise['title'])->toBe('Κρουαζιέρα στην Αίγινα')
        ->and($cruise['is_booking'])->toBeTrue()
        ->and($cruise['person_types'])->toBe(['55' => '65', '56' => '35', '57' => '0'])
        ->and($cruise['duration'])->toBe(8)
        ->and($cruise['max_persons'])->toBe(12)
        ->and($cruise['category_ids'])->toBe(['12'])
        // WordPress markup becomes plain paragraphs.
        ->and($cruise['description'])->not->toContain('<p>');

    // A cap sold in the same shop is read, so the review can say why it is left out.
    expect($wxr['products'][3]['is_booking'])->toBeFalse();
})->group('fast');

it('reads YITH bookings by header meaning, with Greek names and euros', function (): void {
    $rows = (new BookingsCsvReader)->read(importFixture('yith-bookings.csv'));

    expect($rows)->toHaveCount(7)
        ->and($rows[0]['customer'])->toBe('Ελένη Παπαδοπούλου')
        ->and($rows[0]['person_types'])->toBe('Ενήλικας: 2 | Παιδί: 1')
        ->and($rows[6]['email'])->toBeNull();
})->group('fast');

it('reads a semicolon CSV, the way a Greek Excel saves one', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kaiki') . '.csv';
    file_put_contents($path, "\xEF\xBB\xBFBooking ID;Product ID;Κατάσταση;From;Persons\n77;1421;paid;20/09/2026 09:00;2\n");

    $rows = (new BookingsCsvReader)->read($path);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('77')
        ->and($rows[0]['status'])->toBe('paid')
        ->and(SourceValues::localDateTime($rows[0]['from']))->toBe(['date' => '2026-09-20', 'time' => '09:00']);

    @unlink($path);
})->group('fast');

it('turns a Greek or English price into cents without a float', function (): void {
    expect(SourceValues::cents('165,00 €'))->toBe(16500)
        ->and(SourceValues::cents('1.234,50'))->toBe(123450)
        ->and(SourceValues::cents('1,234.50'))->toBe(123450)
        ->and(SourceValues::cents('65'))->toBe(6500)
        ->and(SourceValues::cents(''))->toBeNull()
        ->and(SourceValues::personCounts('Ενήλικας: 2 | Παιδί: 1'))->toBe(['Ενήλικας' => 2, 'Παιδί' => 1])
        ->and(SourceValues::personCounts('2 x Adult, 1 x Child'))->toBe(['Adult' => 2, 'Child' => 1]);
})->group('fast');

it('refuses a file that is not a WordPress export, with a sentence', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kaiki') . '.xml';
    file_put_contents($path, 'not xml at all');

    expect(fn () => (new WxrReader)->read($path))->toThrow(ImportFileUnreadable::class);

    @unlink($path);
})->group('fast');
