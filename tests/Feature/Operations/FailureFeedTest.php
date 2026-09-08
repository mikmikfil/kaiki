<?php

declare(strict_types=1);

use App\Domain\Operations\Support\FailureFeed;
use App\Enums\ExportStatus;
use App\Enums\FailureSource;
use App\Enums\NotificationStatus;
use App\Enums\PaymentStatus;
use App\Models\ExportJob;
use App\Models\IcalSource;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| #127 — one feed for every failure an operator can see (OPS-21, NFR-8)
|--------------------------------------------------------------------------
|
| The feed is a **view over six tables**, not a seventh table, and almost
| everything worth testing follows from that: that each source appears, that
| none of them can crowd the others out, that the retry goes to the right place,
| and that a row an operator cannot act on does not pretend otherwise.
|
| The last of those is the one that matters most. A retry button on a refused
| card would be a control that looks like it did something and did not — and the
| operator would believe the guest had been charged again.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
});

it('gathers a failure from every source that has one', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        NotificationLog::factory()->create([
            'status' => NotificationStatus::Failed,
            'error_message' => 'bounced_hard',
        ]);

        Payment::factory()->create(['status' => PaymentStatus::Failed]);

        ExportJob::factory()->create(['status' => ExportStatus::Failed, 'error' => 'disk full']);

        IcalSource::factory()->failing()->create();

        WebhookDelivery::factory()->failed()->create();
    });

    $sources = array_map(
        static fn ($item) => $item->source,
        Tenancy::forTenant($tenant, fn (): array => app(FailureFeed::class)->all()),
    );

    expect($sources)->toContain(FailureSource::Notification)
        ->and($sources)->toContain(FailureSource::Payment)
        ->and($sources)->toContain(FailureSource::Export)
        ->and($sources)->toContain(FailureSource::IcalSync)
        ->and($sources)->toContain(FailureSource::OutboundWebhook);
});

it('says nothing about anything that worked', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        NotificationLog::factory()->create(['status' => NotificationStatus::Sent]);
        Payment::factory()->create(['status' => PaymentStatus::Succeeded]);
        ExportJob::factory()->create(['status' => ExportStatus::Ready]);
        WebhookDelivery::factory()->delivered()->create();
        // Below the threshold: a calendar that failed twice is having an
        // afternoon, not a problem (OPS-15).
        IcalSource::factory()->create(['consecutive_failures' => 1]);
    });

    expect(Tenancy::forTenant($tenant, fn (): array => app(FailureFeed::class)->all()))->toBe([]);
});

it('puts the newest failure first', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        NotificationLog::factory()->create([
            'status' => NotificationStatus::Failed,
            'created_at' => Carbon::now()->subDays(3),
        ]);

        ExportJob::factory()->create([
            'status' => ExportStatus::Failed,
            'created_at' => Carbon::now()->subHour(),
        ]);
    });

    $items = Tenancy::forTenant($tenant, fn (): array => app(FailureFeed::class)->all());

    expect($items[0]->source)->toBe(FailureSource::Export);
});

it('forgets a failure older than a month', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        NotificationLog::factory()->create([
            'status' => NotificationStatus::Failed,
            'created_at' => Carbon::now()->subDays(FailureFeed::DAYS + 1),
        ]);
    });

    // A feed that goes back further becomes a list nobody scrolls to the bottom
    // of, which is the same as a feed nobody reads.
    expect(Tenancy::forTenant($tenant, fn (): array => app(FailureFeed::class)->all()))->toBe([]);
});

it('keeps a broken calendar however long it has been broken', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        IcalSource::factory()->failing()->create([
            'last_synced_at' => Carbon::now()->subMonths(3),
        ]);
    });

    // The one source with no date filter, and deliberately so: a calendar dead
    // for three months is more urgent than one that broke this morning, not
    // less, and the thirty-day cut would hide exactly the worst case.
    $items = Tenancy::forTenant($tenant, fn (): array => app(FailureFeed::class)->all());

    expect($items)->toHaveCount(1)
        ->and($items[0]->source)->toBe(FailureSource::IcalSync);
});

it('lets no single source crowd the others off the screen', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        WebhookDelivery::factory()->failed()->count(FailureFeed::PER_SOURCE + 10)->create();

        ExportJob::factory()->create(['status' => ExportStatus::Failed]);
    });

    $items = Tenancy::forTenant($tenant, fn (): array => app(FailureFeed::class)->all());

    $webhooks = array_filter($items, static fn ($i) => $i->source === FailureSource::OutboundWebhook);
    $exports = array_filter($items, static fn ($i) => $i->source === FailureSource::Export);

    // The cap is per source rather than overall. A hundred failed webhooks must
    // not hide the one refused export.
    expect($webhooks)->toHaveCount(FailureFeed::PER_SOURCE)
        ->and($exports)->toHaveCount(1);
});

it('offers a retry only where one would do something', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        NotificationLog::factory()->create(['status' => NotificationStatus::Failed]);
        Payment::factory()->create(['status' => PaymentStatus::Failed]);
        IcalSource::factory()->failing()->create();
    });

    $items = Tenancy::forTenant($tenant, fn (): array => app(FailureFeed::class)->all());

    $retryable = [];

    foreach ($items as $item) {
        $retryable[$item->source->value] = $item->retryable;
    }

    // A refused card is the guest's to try again, on their own card. An iCal
    // source is already retried every quarter of an hour by the poller. A
    // button on either would be a control that looks like it did something.
    expect($retryable['notification'])->toBeTrue()
        ->and($retryable['payment'])->toBeFalse()
        ->and($retryable['ical_sync'])->toBeFalse();
});

it('explains a failure rather than printing its code', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        NotificationLog::factory()->create([
            'status' => NotificationStatus::Failed,
            'error_message' => 'a_code_nobody_has_translated',
        ]);
    });

    $item = Tenancy::forTenant($tenant, fn (): array => app(FailureFeed::class)->all())[0];

    // NFR-8. The provider's own string is kept in `detail` for whoever wants
    // it; the explanation beside it must never be the raw code, even for a
    // code nobody has written a sentence for yet.
    expect($item->explanation)->not->toBe('a_code_nobody_has_translated')
        ->and($item->explanation)->not->toStartWith('notifications.errors.')
        ->and($item->detail)->toBe('a_code_nobody_has_translated');
});

it('shortens a recipient rather than printing it whole', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        NotificationLog::factory()->create([
            'status' => NotificationStatus::Failed,
            'to' => 'maria.papadopoulou@example.gr',
        ]);
    });

    $item = Tenancy::forTenant($tenant, fn (): array => app(FailureFeed::class)->all())[0];

    // Enough to recognise which guest, and not a screen somebody can photograph
    // to collect addresses. The whole value is on the notification log, behind
    // its own permission.
    expect($item->title)->not->toContain('maria.papadopoulou@example.gr')
        ->and($item->title)->toContain('example.gr');
});

it('shows one operator nothing of another\'s failures', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    Tenancy::forTenant($theirs, function (): void {
        NotificationLog::factory()->create(['status' => NotificationStatus::Failed]);
        ExportJob::factory()->create(['status' => ExportStatus::Failed]);
    });

    expect(Tenancy::forTenant($mine, fn (): array => app(FailureFeed::class)->all()))->toBe([])
        ->and(Tenancy::forTenant($theirs, fn (): array => app(FailureFeed::class)->all()))->toHaveCount(2);
});

it('answers with nothing when no tenant is resolved', function (): void {
    // The same guard every dashboard predicate learnt to carry after
    // `GET /app/login` returned a 500: `BelongsToTenant` throws rather than
    // scoping to nobody, and this class queries six tenant-owned models.
    expect(app(FailureFeed::class)->all())->toBe([]);
});
