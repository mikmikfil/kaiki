<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Gateways;

use App\Contracts\MyDataGateway;
use App\Domain\Compliance\Data\MyDataResult;
use App\Models\Invoice;

/**
 * The gateway for an operator who has not connected myDATA.
 *
 * ## It refuses, and it is important that it refuses
 *
 * {@see NullSmsGateway} swallows a message and reports success, because an
 * unsent text is a small loss and a broken reminder sweep is a large one. This
 * is the opposite case and takes the opposite answer.
 *
 * An invoice marked `sent` is a claim that a document exists in a state tax
 * register. If this returned success, an operator with no credentials would
 * accumulate a shelf of invoices their books say were filed and AADE has never
 * heard of — and they would find out during an audit, which is the worst
 * possible moment and the worst possible discoverer.
 *
 * So it returns {@see MyDataResult::notConfigured()}: not accepted, and not
 * retryable either. Retrying will not conjure a credential, and eight attempts
 * would bury the one line the operator needs to read.
 *
 * ## Which is a feature of the failure feed, not a gap in it
 *
 * The invoice lands in OPS-21's list saying «Δεν έχει συνδεθεί το myDATA», with
 * the booking beside it. That is an operator finding out on day one that
 * issuing is switched off, which is exactly what MYD-16's activation gate is
 * for and exactly what silence would prevent.
 */
final class NullMyDataGateway implements MyDataGateway
{
    public function submit(Invoice $invoice): MyDataResult
    {
        // Deliberately nothing. The invoice row exists, the operator can see it,
        // and its status says why it went no further.
        return MyDataResult::notConfigured();
    }

    public function environment(): string
    {
        return 'dev';
    }
}
