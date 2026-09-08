<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExportDateBasis;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Models\ExportJob;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<ExportJob> */
class ExportJobFactory extends Factory
{
    protected $model = ExportJob::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'type' => ExportType::Bookings,
            'date_basis' => ExportDateBasis::Booked,
            'from_date' => null,
            'to_date' => null,
            'filters' => [],
            'status' => ExportStatus::Queued,
            'row_count' => 0,
            'byte_size' => 0,
            'download_count' => 0,
        ];
    }

    /**
     * A finished export with a live link.
     *
     * The default is a *fresh* expiry rather than a fixed date, so a test that
     * asserts a download works does not start failing on the day somebody
     * copied its fixture into a slower suite.
     */
    public function ready(string $path = 'exports/1/example.csv'): self
    {
        return $this->state(fn (): array => [
            'status' => ExportStatus::Ready,
            'disk' => 'local',
            'path' => $path,
            'filename' => 'kaiki-bookings.csv',
            'row_count' => 3,
            'byte_size' => 512,
            'completed_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDay(),
        ]);
    }

    /** Finished, and past its day — the file may or may not still be there. */
    public function expiredLink(): self
    {
        return $this->ready()->state(fn (): array => [
            'expires_at' => Carbon::now()->subHour(),
        ]);
    }

    /** Swept: the row survives, the file does not. */
    public function purged(): self
    {
        return $this->state(fn (): array => [
            'status' => ExportStatus::Expired,
            'disk' => null,
            'path' => null,
            'expires_at' => Carbon::now()->subDay(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => ExportStatus::Failed,
            'error' => 'The file could not be prepared.',
            'completed_at' => Carbon::now(),
        ]);
    }

    public function guests(): self
    {
        return $this->state(fn (): array => [
            'type' => ExportType::Guests,
            'date_basis' => ExportDateBasis::Departure,
        ]);
    }
}
