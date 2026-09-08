<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Actions;

use App\Contracts\MyDataGateway;
use App\Domain\Compliance\Support\InvoiceTypeResolver;
use App\Domain\Compliance\Support\VatNumber;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Jobs\SubmitInvoiceToMyData;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Write the document, ready to be sent (spec MYD-2, MYD-3, MYD-7, MYD-14,
 * SAA-12, BKG-34).
 *
 * ## This does not talk to AADE
 *
 * It builds the row and stops. Sending is
 * {@see SubmitInvoiceToMyData}, because MYD-14 requires every myDATA
 * call to be queued and never made inline in a web request — an operator
 * pressing "issue" must not wait on a tax authority, and a guest completing a
 * checkout must not wait on one at all.
 *
 * ## Idempotent on (booking, type, series)
 *
 * MYD-3.6 and MYD-14. The confirmation path, a queue retry and an operator
 * pressing the button can all arrive at the same booking, and a second ΑΛΠ for
 * one sale is a document that has to be undone by a third. Enforced by looking
 * for an existing non-cancelled document of the same type before writing.
 *
 * A **credit note is exempt**: MYD-13 allows several against one invoice,
 * because a partial refund followed by the rest is two documents and a schema
 * that assumed one would make the second impossible to record.
 *
 * ## The amounts come from the frozen snapshot, not from a fresh calculation
 *
 * `price_snapshot.vat` carries `rate_bp`, `vat_category`, `net_cents` and
 * `vat_cents`, snapshotted at pricing time (PRC-14). Re-deriving them here would
 * read today's `vat_rates` and could rewrite last season's document after a
 * statutory change — which is the exact failure ADR-0002 built the snapshot to
 * prevent. **The client contains no percent and no percent-to-category map**
 * (CAT-11a), and neither does this.
 *
 * MYD-7's invariant — line nets plus line VAT equalling the gross exactly — is
 * already true in the snapshot, because `ComputePrice` takes VAT as the
 * remainder rather than rounding both halves independently. Asserted here rather
 * than recomputed, so a snapshot that ever stopped satisfying it fails loudly at
 * issuance instead of silently at the tax office.
 *
 * ## Two bookings that never produce a document
 *
 * **SAA-12** — a test booking is excluded from every myDATA issuance. A sandbox
 * sale registered with a real tax authority is a document somebody has to
 * cancel, and explain.
 *
 * **BKG-34** — an imported booking fires no invoice. The sale happened in
 * WooCommerce and was invoiced there; issuing again would duplicate it in the
 * operator's own series.
 */
final class IssueInvoice
{
    /**
     * @param  InvoiceType|null  $type  null resolves it from the booking (MYD-3.1)
     */
    public function __invoke(
        Booking $booking,
        ?InvoiceType $type = null,
        ?User $issuedBy = null,
        ?Invoice $cancels = null,
    ): Invoice {
        $tenant = Tenancy::current();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Issuing an invoice needs a resolved tenant.');
        }

        $this->assertIssuable($booking, $tenant);

        $type ??= InvoiceTypeResolver::for($booking);
        $series = (string) ($tenant->invoice_series ?: 'A');

        $existing = $this->existing($booking, $type, $series);

        if ($existing instanceof Invoice) {
            return $existing;
        }

        return DB::transaction(fn (): Invoice => Invoice::query()->create([
            'booking_id' => $booking->getKey(),
            'type' => $type,
            'cancels_invoice_id' => $cancels?->getKey(),
            'series' => $series,
            // MYD-4.2 — no number until the send attempt.
            'number' => null,
            'year' => (int) $booking->created_at?->year ?: (int) now($tenant->timezone)->year,
            ...$this->amounts($booking),
            ...$this->counterparty($booking, $type),
            'status' => InvoiceStatus::Pending,
            'environment' => app(MyDataGateway::class)->environment(),
            'issued_by_user_id' => $issuedBy?->getKey(),
        ]));
    }

    /**
     * Refuse the bookings that must never produce a document, with a reason.
     *
     * Exceptions rather than a silent `null` return: every one of these is a
     * caller mistake, and a caller that quietly got nothing back would carry on
     * as though a document existed.
     */
    private function assertIssuable(Booking $booking, Tenant $tenant): void
    {
        if ($tenant->invoicing_mode === 'external') {
            // MYD-4.5. The operator issues through their own software; a second
            // document against the same sale is a duplicate in their register.
            throw new RuntimeException('This operator issues invoices externally (MYD-4.5).');
        }

        if ($booking->is_test) {
            throw new RuntimeException('A test booking is never issued to myDATA (SAA-12).');
        }

        // The enum case, not the string. `source` is cast, so `=== 'import'`
        // is never true and the guard silently did nothing — which is exactly
        // the shape of bug a requirement like BKG-34 acquires when nothing
        // exercises it.
        if ($booking->source === BookingSource::Import) {
            throw new RuntimeException('An imported booking was invoiced at its source (BKG-34).');
        }

        if (! in_array($booking->status, [
            BookingStatus::Confirmed,
            BookingStatus::CheckedIn,
            BookingStatus::Completed,
            BookingStatus::Cancelled,
            BookingStatus::Refunded,
        ], strict: true)) {
            // A draft or an unpaid hold is not a sale. Cancelled and refunded
            // are here because a credit note is raised against exactly those.
            throw new RuntimeException("A booking in {$booking->status->value} has nothing to invoice.");
        }
    }

    /**
     * An existing document of this type, if one was already issued.
     *
     * Cancelled ones do not count: a sale that was credited and is being
     * re-issued needs a new document, which is MYD-3.7's path when tax details
     * arrive late.
     *
     * Credit notes are never matched — see the class docblock.
     */
    private function existing(Booking $booking, InvoiceType $type, string $series): ?Invoice
    {
        if ($type->isCredit()) {
            return null;
        }

        return Invoice::query()
            ->where('booking_id', $booking->getKey())
            ->where('type', $type)
            ->where('series', $series)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->first();
    }

    /**
     * The money, read from the frozen snapshot.
     *
     * @return array<string, int|string|null>
     */
    private function amounts(Booking $booking): array
    {
        $snapshot = $booking->price_snapshot ?? [];
        $vat = is_array($snapshot['vat'] ?? null) ? $snapshot['vat'] : [];

        $total = (int) $booking->total_cents;
        $net = (int) ($vat['net_cents'] ?? 0);
        $vatCents = (int) ($vat['vat_cents'] ?? 0);

        if ($net + $vatCents !== $total) {
            // MYD-7's invariant. Loud here rather than silent at the tax office:
            // a document whose halves do not sum to its total is one AADE will
            // refuse, and the refusal will not say which of the three is wrong.
            throw new RuntimeException(
                "Booking {$booking->reference}: net {$net} + VAT {$vatCents} ≠ total {$total} (MYD-7).",
            );
        }

        return [
            'net_cents' => $net,
            'vat_cents' => $vatCents,
            'total_cents' => $total,
            'vat_rate_bp' => (int) ($vat['rate_bp'] ?? $booking->vat_rate_bp ?? 0),
            // Straight from the snapshot. CAT-11a forbids deriving a category
            // from a percentage anywhere in `app/`, and a scanner enforces it.
            'vat_category' => $vat['vat_category'] ?? $booking->vat_category,
        ];
    }

    /**
     * The counterparty block, on a ΤΠΥ only.
     *
     * An ΑΛΠ has no counterparty — it is a retail receipt — and writing the
     * guest's details onto one would put a private person's ΑΦΜ in a document
     * that never needed it.
     *
     * @return array<string, string|null>
     */
    private function counterparty(Booking $booking, InvoiceType $type): array
    {
        if ($type !== InvoiceType::Tpy) {
            return [
                'counterparty_vat' => null,
                'counterparty_name' => null,
                'counterparty_country' => null,
            ];
        }

        return [
            // Normalised, so «EL 094 014 201» reaches AADE as the nine digits
            // it expects rather than as the string a customer typed.
            'counterparty_vat' => VatNumber::normalise($booking->guest_vat_number),
            'counterparty_name' => $booking->guest_company_name,
            'counterparty_country' => strtoupper((string) ($booking->guest_country ?: VatNumber::GREECE)),
        ];
    }
}
