<?php

declare(strict_types=1);

use App\Domain\Operations\Actions\RequestExport;
use App\Enums\BookingStatus;
use App\Enums\ExportDateBasis;
use App\Enums\ExportType;
use App\Jobs\RunExportJob;
use App\Models\Booking;
use App\Models\ExportJob;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Export timestamps on the operator's clock (CNV-2)
|--------------------------------------------------------------------------
|
| The window is chosen in local days; the timestamps in the file were UTC, so
| a «from 1 October» export listed a row created «2026-09-30 21:30».
|
*/

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    Carbon::setTestNow('2026-10-05 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('prints created and cancelled times in the tenant timezone', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    Tenancy::forTenant($tenant, fn () => Booking::factory()->create([
        'status' => BookingStatus::Cancelled,
        'guest_name' => 'Νυχτερινή Κράτηση',
        'local_date' => '2026-10-10',
        // 00:30 on 1 October in Athens.
        'created_at' => '2026-09-30 21:30:00',
        'cancelled_at' => '2026-10-01 21:15:00',
    ]));

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(
        type: ExportType::Bookings,
        basis: ExportDateBasis::Booked,
        from: Carbon::parse('2026-10-01'),
        to: Carbon::parse('2026-10-01'),
    ));

    (new RunExportJob($export->getKey()))->handle();

    $export->refresh();
    $csv = (string) Storage::disk((string) $export->disk)->get((string) $export->path);

    expect($csv)->toContain('Νυχτερινή Κράτηση')
        ->and($csv)->toContain('2026-10-01 00:30:00')
        ->and($csv)->toContain('2026-10-02 00:15:00')
        ->and($csv)->not->toContain('2026-09-30 21:30');
})->group('fast');
