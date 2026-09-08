<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where one invoice has got to with AADE (spec MYD-5, MYD-10).
 *
 * ## `sent` means AADE said so, and nothing else does
 *
 * MYD-5: *"An `Invoice` moves `pending` to `sent` only on an AADE response
 * containing a `mark`."* Not a 200, not an empty body, not a queued job that
 * completed — a MARK. It is the number the tax authority hands back to say the
 * document exists in their register, and it is the only thing that makes the
 * transition true rather than hopeful.
 *
 * The distinction is the whole reason this enum is not a boolean. An operator
 * looking at a list needs to tell "the tax office has this" from "we think we
 * sent it", and those are different mornings.
 */
enum InvoiceStatus: string
{
    use HasTranslatedLabel;

    /**
     * Written, not yet accepted. **No number allocated.**
     *
     * MYD-4.2: the number is taken at the send attempt, never at row creation,
     * so a document that is never submitted never burns one. A `pending` row
     * with a `number` is a bug, and the model's docblock says why.
     */
    case Pending = 'pending';

    /** AADE returned a MARK. The document exists in the register. */
    case Sent = 'sent';

    /**
     * The attempts ran out — eight over roughly twenty-four hours (MYD-10).
     *
     * Sits in the operator's failure feed with a manual retry. Distinct from
     * `pending` because a `pending` row is still going to be tried on its own,
     * and a row nobody will touch again unless a person presses a button is a
     * different thing to look at.
     */
    case Failed = 'failed';

    /**
     * Undone by a later credit note (MYD-13).
     *
     * Not deleted, and it never can be: `docs/data-model.md` §1.4 puts invoices
     * among the rows no code path removes. A cancelled sale in a Greek series is
     * a document that exists and is answered by another document.
     */
    case Cancelled = 'cancelled';

    /** Is this one still going to be tried again without anybody asking? */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /** Does this row belong in OPS-21's failure feed, with a retry button? */
    public function needsAttention(): bool
    {
        return $this === self::Failed;
    }

    /** Has AADE accepted this document? */
    public function isRegistered(): bool
    {
        return $this === self::Sent;
    }

    /**
     * The Filament badge colour.
     *
     * `cancelled` is grey rather than red: a credit note is a correct outcome,
     * and colouring it as a failure teaches an operator to read an ordinary
     * refund as a problem.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
