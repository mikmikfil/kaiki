<?php

declare(strict_types=1);

namespace App\Support\Format;

/**
 * The DOM id a validation key belongs to.
 *
 * The checkout page turned off the browser's own validation (it speaks the
 * device's language, not the guest's, and reports one field at a time), so the
 * server's messages are now the only ones a guest sees. Listing them at the top
 * of the form is only useful if each one is a link to the field it came from —
 * and Laravel's key is `guests.1.document_number` while the input's id is
 * `g1_doc`. This is the one place that knows both.
 *
 * A key with no mapping returns the key itself. The link then points at nothing
 * and does nothing, which is what an unknown field deserves: the message is
 * still read out, and a dead anchor is better than a guessed one that scrolls
 * somebody to the wrong box.
 */
final class FieldAnchor
{
    /** `guests.{n}.{field}` — the per-passenger rows, which are the only indexed ones. */
    private const GUEST_FIELDS = [
        'full_name' => 'name',
        'nationality' => 'nat',
        'date_of_birth' => 'dob',
        'document_type' => 'dtype',
        'document_number' => 'doc',
        'document_expires_on' => 'dexp',
    ];

    public static function for(string $key): string
    {
        if (preg_match('/^guests\.(\d+)\.(\w+)$/', $key, $m) === 1) {
            $suffix = self::GUEST_FIELDS[$m[2]] ?? null;

            return $suffix === null ? $key : sprintf('g%d_%s', (int) $m[1], $suffix);
        }

        // The operator's checkout questions (2026-09-17).
        if (preg_match('/^answers\.booking\.([\w-]+)$/', $key, $m) === 1) {
            return 'q_' . $m[1];
        }

        if (preg_match('/^answers\.guests\.(\d+)\.([\w-]+)$/', $key, $m) === 1) {
            return sprintf('g%d_q_%s', (int) $m[1], $m[2]);
        }

        // The lead booker's fields, the note and the consent box all carry their
        // own name as their id, so there is nothing to translate.
        return $key;
    }
}
