<?php

declare(strict_types=1);

use App\Domain\Notifications\Support\SmsComposer;

/*
|--------------------------------------------------------------------------
| NTF-5: what a text message really costs
|--------------------------------------------------------------------------
|
| > *SMS bodies are at most 160 GSM-7 characters where possible; **Greek text
| > falls back to UCS-2** and the composer MUST warn the operator about segment
| > count. Every SMS includes the meeting point and time and a short link.*
|
| The Greek half is the whole problem. GSM-7 does not contain the Greek
| lowercase alphabet, so an ordinary Greek sentence forces UCS-2 and a segment
| drops from 160 characters to **70**. That is the number the operator is billed
| on, and it is the difference between one message and three for the same
| sentence in two languages.
|
| The last test is the one NTF-5 actually protects: when the budget is spent,
| the **meeting point and the link survive** and the operator's preamble is what
| gets cut. A text that arrived without a meeting point is a phone call.
|
*/

it('counts a plain English sentence as one GSM-7 segment', function (): void {
    $body = 'Booking confirmed. Zea Marina 04/07 09:00';

    expect(SmsComposer::isGsm7($body))->toBeTrue()
        ->and(SmsComposer::encoding($body))->toBe('GSM-7')
        ->and(SmsComposer::segments($body))->toBe(1);
})->group('fast');

it('falls back to UCS-2 the moment a Greek letter appears', function (): void {
    $body = 'Η κράτησή σας επιβεβαιώθηκε';

    // One accented vowel is enough — there is no mixing, and the **whole**
    // message changes encoding.
    expect(SmsComposer::isGsm7($body))->toBeFalse()
        ->and(SmsComposer::encoding($body))->toBe('UCS-2');
})->group('fast');

it('halves the capacity for Greek, which is the surprise on the bill', function (): void {
    // Eighty characters: comfortably one segment in English, two in Greek.
    $english = str_repeat('a', 80);
    $greek = str_repeat('α', 80);

    expect(SmsComposer::segments($english))->toBe(1)
        ->and(SmsComposer::segments($greek))->toBe(2);
})->group('fast');

it('charges an escape-table character twice', function (): void {
    // `€` is in GSM-7's escape table and costs two septets. A hundred of them
    // is two hundred units — two segments, where a naive `strlen` says one.
    $body = str_repeat('€', 100);

    expect(SmsComposer::isGsm7($body))->toBeTrue()
        ->and(SmsComposer::units($body))->toBe(200)
        ->and(SmsComposer::segments($body))->toBe(2);
})->group('fast');

it('accounts for the concatenation header rather than dividing by 160', function (): void {
    // 161 characters. A naive `ceil(161 / 160)` also says two — so the case
    // that separates the two arithmetics is 306: two segments at 153 each, and
    // one *more* than 160 × 2 would allow.
    expect(SmsComposer::segments(str_repeat('a', 161)))->toBe(2)
        ->and(SmsComposer::segments(str_repeat('a', 306)))->toBe(2)
        ->and(SmsComposer::segments(str_repeat('a', 307)))->toBe(3);
})->group('fast');

it('uses sixty-seven characters a segment once Greek concatenates', function (): void {
    // UCS-2's own concatenation cost: 70 in one segment, 67 each after that.
    expect(SmsComposer::segments(str_repeat('α', 70)))->toBe(1)
        ->and(SmsComposer::segments(str_repeat('α', 71)))->toBe(2)
        ->and(SmsComposer::segments(str_repeat('α', 134)))->toBe(2)
        ->and(SmsComposer::segments(str_repeat('α', 135)))->toBe(3);
})->group('fast');

it('counts nothing for an empty body', function (): void {
    expect(SmsComposer::segments(''))->toBe(0);
})->group('fast');

it('keeps the meeting point and the link when the budget runs out', function (): void {
    // A Greek lead long enough to blow two segments on its own.
    $lead = str_repeat('Καλησπέρα σας από το γραφείο μας. ', 6);

    $body = SmsComposer::compose(
        lead: $lead,
        meetingPoint: 'Μαρίνα Ζέας',
        when: '04/07 09:00',
        link: 'https://kaiki.test/b/abc',
        maxSegments: 2,
    );

    // NTF-5 fixes three things as mandatory, and all three survive.
    expect($body)->toContain('Μαρίνα Ζέας')
        ->toContain('04/07 09:00')
        ->toContain('https://kaiki.test/b/abc')
        // Within the budget the operator agreed to pay for.
        ->and(SmsComposer::segments($body))->toBeLessThanOrEqual(2)
        // And the operator's own preamble is what was cut.
        ->and(mb_strlen($body))->toBeLessThan(mb_strlen($lead));
})->group('fast');

it('leaves a short message exactly as written', function (): void {
    $body = SmsComposer::compose(
        lead: 'Booking confirmed.',
        meetingPoint: 'Zea Marina',
        when: '04/07 09:00',
        link: 'https://kaiki.test/b/abc',
    );

    // Nothing to trim, so nothing is trimmed — no stray ellipsis on a message
    // that fitted.
    expect($body)->toBe('Booking confirmed. Zea Marina 04/07 09:00 https://kaiki.test/b/abc')
        ->and($body)->not->toContain('…');
})->group('fast');

it('drops the lead entirely rather than the link, when even that is not enough', function (): void {
    // A meeting point long enough that the mandatory part alone blows the
    // budget. There is no room for a lead at all — and NTF-5's three things
    // still go out, over budget, rather than a message with a hole in it.
    $body = SmsComposer::compose(
        lead: 'Υπενθύμιση',
        meetingPoint: str_repeat('Μαρίνα Ζέας ', 12),
        when: '04/07 09:00',
        link: 'https://kaiki.test/b/abcdefghijklmnop',
        maxSegments: 2,
    );

    expect($body)->toContain('https://kaiki.test/b/abcdefghijklmnop')
        ->toContain('04/07 09:00')
        // Not a truncated lead with an ellipsis: nothing of it survives, which
        // is the difference between "trim the prose" and "there is no prose".
        ->and($body)->not->toContain('Υπεν')
        ->and($body)->not->toContain('…');
})->group('fast');
