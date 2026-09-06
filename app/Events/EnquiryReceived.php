<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody asked a question (spec BKG-29).
 *
 * BKG-29: the operator is notified **immediately**. Immediately is the word
 * that matters — an enquiry is a person waiting for an answer, and a digest
 * would turn a five-minute reply into a next-morning one.
 *
 * Ids only, and no message body: the guest's own words live on the row, and an
 * event payload is a queue payload is a log line.
 *
 * **Nothing listens yet.** The operator email is #87's.
 */
final class EnquiryReceived
{
    use Dispatchable;

    public function __construct(
        public readonly int $enquiryId,
        public readonly int $tenantId,
    ) {}
}
