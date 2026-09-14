<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Analytics\Actions\CountAnalyticsEvent;
use App\Domain\Analytics\Support\AnalyticsMetric;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AnalyticsDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day's count of one thing (ADR-0032).
 *
 * Read through Eloquent so `BelongsToTenant` scopes it like every other
 * tenant-owned table; **written** through {@see CountAnalyticsEvent}, which
 * goes to the query builder directly because the write is an atomic increment
 * rather than a model save, and a read-modify-write through a model is the one
 * shape that loses counts under concurrency.
 *
 * There is no uuid and no public identifier: nothing outside the panel ever
 * refers to one of these rows.
 *
 * @property int $id
 * @property int $tenant_id
 * @property Carbon $date
 * @property AnalyticsMetric $metric
 * @property string $dimension
 * @property string $dimension_value
 * @property int $count
 * @property int $value_cents
 */
class AnalyticsDaily extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AnalyticsDailyFactory> */
    use HasFactory;

    protected $table = 'analytics_daily';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'metric' => AnalyticsMetric::class,
            'count' => 'integer',
            'value_cents' => 'integer',
        ];
    }
}
