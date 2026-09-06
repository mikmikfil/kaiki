<?php

declare(strict_types=1);

use App\Enums\AgreementStatus;
use App\Enums\Role;
use App\Exceptions\AgreementEvidenceLocked;
use App\Models\Booking;
use App\Models\CharterAgreement;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| §2.6 and §6 item 45: an M6 table, landing in M2, with its one invariant
|--------------------------------------------------------------------------
|
| The **document** is M6 and nothing generates one yet. The **table** lands now
| because §0 forbids adding a foreign key to an existing table on SQLite, and
| this one has three — the same move #29 made for the iCal tables and #47 made
| for `vat_rates`.
|
| What lands with it is the rule that would be expensive to add afterwards:
|
| > Acceptance evidence (timestamp + IP + user agent + typed name) is the
| > legally interesting part and is never overwritten. Regenerating after the
| > guest accepted is forbidden by the application — you create a new version
| > instead.
|
| A rule stated only in prose is a rule the first M6 implementation breaks, and
| the thing it breaks is the only record that a guest accepted a contract.
|
*/

it('has every column the agreement will ever need', function (): void {
    // §6 item 45's ordering problem, asserted directly. SQLite cannot add a
    // foreign key afterwards, so a column missing here is a table rebuild in
    // M6 — of a table holding legal evidence.
    foreach ([
        'uuid', 'tenant_id', 'booking_id',
        'template_key', 'template_version', 'fields_snapshot',
        'pdf_path', 'pdf_hash', 'generated_at', 'sent_at',
        'guest_accepted_at', 'guest_accepted_ip', 'guest_accepted_user_agent', 'guest_accepted_name',
        'operator_signed_at', 'operator_signed_by_user_id', 'status',
    ] as $column) {
        expect(Schema::hasColumn('charter_agreements', $column))->toBeTrue($column);
    }
})->group('fast');

it('lets an unaccepted agreement be regenerated in place', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        $agreement = CharterAgreement::factory()->create();

        // Nobody has accepted it, so regenerating is just regenerating a draft.
        $agreement->update(['pdf_hash' => hash('sha256', 'second render')]);

        expect($agreement->refresh()->pdf_hash)->toBe(hash('sha256', 'second render'));
    });
})->group('fast');

it('refuses to overwrite the evidence on an accepted agreement', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        $agreement = CharterAgreement::factory()->accepted()->create();

        // The exact shape a regeneration takes: same row, new PDF, and
        // `guest_accepted_at` quietly replaced. The unique index does nothing
        // about it, because this is an update rather than a second row.
        expect(fn () => $agreement->update([
            'pdf_path' => 'tenants/1/agreements/rewritten.pdf',
            'guest_accepted_at' => null,
        ]))->toThrow(AgreementEvidenceLocked::class);

        expect($agreement->refresh()->guest_accepted_at)->not->toBeNull()
            ->and($agreement->pdf_path)->not->toBe('tenants/1/agreements/rewritten.pdf');
    });
})->group('fast');

it('refuses to move an accepted agreement out of accepted', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        $agreement = CharterAgreement::factory()->accepted()->create();

        // §2.6 permits voiding *"only by explicit operator action"*, and only
        // before acceptance. Voiding afterwards would erase the guest's own
        // acceptance under the cover of a status change.
        expect(fn () => $agreement->update(['status' => AgreementStatus::Void]))
            ->toThrow(AgreementEvidenceLocked::class);

        expect($agreement->refresh()->status)->toBe(AgreementStatus::Accepted);
    });
})->group('fast');

it('still allows the operator to countersign afterwards', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        $agreement = CharterAgreement::factory()->accepted()->create();

        // Countersigning after the guest accepted is the normal order of
        // events, not a tampering attempt — which is why the operator's
        // signature is absent from `FROZEN_ONCE_ACCEPTED`.
        $agreement->update([
            'operator_signed_at' => now(),
            'operator_signed_by_user_id' => $owner->getKey(),
        ]);

        expect($agreement->refresh()->operator_signed_at)->not->toBeNull();
    });
})->group('fast');

it('opens a new version rather than touching an accepted one', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        /** @var Booking $booking */
        $booking = Booking::factory()->create();

        $accepted = CharterAgreement::factory()->accepted()->create([
            'booking_id' => $booking->getKey(),
            'template_version' => '2026.1',
        ]);

        $next = CharterAgreement::openVersionFor($booking, '2026.1');
        $next->save();

        expect($next->getKey())->not->toBe($accepted->getKey())
            // A **suffix**, not arithmetic on the operator's own version:
            // `2026.2` is a template version somebody else chose and may
            // already exist saying something different.
            ->and($next->template_version)->toBe('2026.1-v2')
            ->and($next->status)->toBe(AgreementStatus::Draft)
            // And the accepted row is exactly as it was.
            ->and($accepted->refresh()->status)->toBe(AgreementStatus::Accepted)
            ->and($accepted->guest_accepted_name)->toBe('Μαρία Παπαδοπούλου');
    });
})->group('fast');

it('reuses the row when nobody has accepted it', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        /** @var Booking $booking */
        $booking = Booking::factory()->create();

        $draft = CharterAgreement::factory()->create([
            'booking_id' => $booking->getKey(),
            'template_version' => '2026.1',
        ]);

        expect(CharterAgreement::openVersionFor($booking, '2026.1')->getKey())->toBe($draft->getKey());
    });
})->group('fast');

it('encrypts the field snapshot at rest', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        $agreement = CharterAgreement::factory()->create();

        // §3.8. Both parties' names, the skipper and the vessel's registration
        // go in here; a plaintext column would put a contract's contents in
        // every database dump.
        $raw = (string) DB::table('charter_agreements')
            ->where('id', $agreement->getKey())
            ->value('fields_snapshot');

        expect($raw)->not->toContain('Παπαδοπούλου')
            ->and($agreement->refresh()->fields_snapshot['charterer'] ?? null)->toBe('Μαρία Παπαδοπούλου');
    });
})->group('fast');

it('keeps crew away from the contract entirely', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    Tenancy::forTenant($crew->tenant, function () use ($crew): void {
        $agreement = CharterAgreement::factory()->create();

        // A charter agreement carries the charterer's full name and the agreed
        // price — a contract, not a manifest line. TEN-8: crew get *"no
        // pricing, no financials"*, so `ViewPaxList` is deliberately not what
        // gates this.
        expect(Gate::forUser($crew)->allows('view', $agreement))->toBeFalse()
            ->and(Gate::forUser($crew)->allows('viewAny', CharterAgreement::class))->toBeFalse();
    });
})->group('fast');

it('lets nobody delete one, not even an owner', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        $agreement = CharterAgreement::factory()->accepted()->create();

        // §2.6: *"never deleted"*. The row is the only record that the guest
        // accepted, which is precisely what a dispute a year later is about.
        expect(Gate::forUser($owner)->allows('delete', $agreement))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('forceDelete', $agreement))->toBeFalse();
    });
})->group('fast');
