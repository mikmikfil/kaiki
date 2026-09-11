<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use App\Domain\Operations\Support\FailureFeed;
use App\Enums\InvoiceStatus;
use App\Models\GatewayWebhookEvent;
use App\Models\IcalSource;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The platform's own health, across every operator (spec SAA-17).
 *
 * > **SAA-17** Platform health in `/admin` shows queue depth and oldest job
 * > age, failed job counts by class, myDATA failure counts per tenant, gateway
 * > webhook failure counts, and iCal sync failures.
 *
 * ## Cross-tenant on purpose, and said so at every query that needs it
 *
 * Everywhere else in this application a query that returns two operators' rows
 * is the defect #8 exists to catch. Here it is the whole point — the platform
 * owner asks "who is in trouble", and the answer is a list of operators. So the
 * two tenant-owned sources, `invoices` and `ical_sources`, are read inside
 * {@see Tenancy::withoutTenancy()}, named at the call rather than hidden in a
 * helper, so the next reader sees exactly where the wall is lowered and why.
 * `gateway_webhook_events` is platform-owned already (its tenant is filled in
 * later, §2.7) and `jobs` / `failed_jobs` have no tenant at all.
 *
 * ## The same definitions as the operator's own feed
 *
 * OPS-21's {@see FailureFeed} already decided what
 * "failed" means for each source — `InvoiceStatus::needsAttention()`,
 * `GatewayWebhookEvent::needingAttention()`, `IcalSource::ATTENTION_THRESHOLD`
 * — and this reuses those rather than re-deriving them. An operator's red badge
 * and the platform's count for that operator must agree, or one of them is
 * lying.
 *
 * ## States, not a date window
 *
 * Unlike the operator feed's thirty days, these are **current** states: an
 * invoice still failed, a webhook still unprocessed, a calendar still unread.
 * A failure fixed last week should not count; one unfixed since spring must.
 */
final class PlatformHealthReport
{
    /**
     * How many failed jobs are read to group by class.
     *
     * The payload is JSON that has to be decoded row by row, and a platform
     * that has failed ten thousand jobs has a different problem than a missing
     * grouping. The total is always exact; the per-class split is over the
     * most recent of these.
     */
    public const FAILED_JOB_SAMPLE = 500;

    /** @return array{driver: string, depth: int, reserved: int, oldest_age_seconds: int|null} */
    public function queue(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        // `jobs.created_at` is a unix timestamp (Laravel's database queue),
        // not a datetime — hence the arithmetic rather than a Carbon cast.
        $oldest = DB::table('jobs')->min('created_at');

        return [
            'driver' => (string) config('queue.default'),
            'depth' => DB::table('jobs')->count(),
            'reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
            'oldest_age_seconds' => $oldest === null ? null : max(0, $now->getTimestamp() - (int) $oldest),
        ];
    }

    public function failedJobsTotal(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /**
     * Job class => how many of the recent failures it accounts for, most first.
     *
     * @return array<string, int>
     */
    public function failedJobsByClass(): array
    {
        $counts = [];

        foreach (DB::table('failed_jobs')->orderByDesc('id')->limit(self::FAILED_JOB_SAMPLE)->pluck('payload') as $payload) {
            $class = self::className((string) $payload);
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * The newest failures, one line each.
     *
     * The first line of the exception only. The whole trace is in the table
     * for anybody on the server, and on a screen it is a wall nobody reads.
     *
     * @return list<array{uuid: string, class: string, queue: string, failed_at: Carbon, error: string}>
     */
    public function recentFailedJobs(int $limit = 15): array
    {
        $rows = [];

        foreach (DB::table('failed_jobs')->orderByDesc('id')->limit($limit)->get(['uuid', 'queue', 'payload', 'exception', 'failed_at']) as $row) {
            $rows[] = [
                'uuid' => (string) $row->uuid,
                'class' => self::className((string) $row->payload),
                'queue' => (string) $row->queue,
                'failed_at' => Carbon::parse((string) $row->failed_at),
                'error' => Str::limit(trim((string) strtok((string) $row->exception, "\n")), 160),
            ];
        }

        return $rows;
    }

    /**
     * Operators with at least one open failure, the most troubled first.
     *
     * @return list<array{tenant_id: int, name: string, mydata: int, gateway_webhooks: int, ical: int, total: int}>
     */
    public function perTenant(): array
    {
        $failedInvoices = array_values(array_map(
            static fn (InvoiceStatus $status): string => $status->value,
            array_filter(InvoiceStatus::cases(), static fn (InvoiceStatus $status): bool => $status->needsAttention()),
        ));

        // Cross-tenant, deliberately — see the class docblock.
        $mydata = Tenancy::withoutTenancy(static fn (): array => self::countByTenant(
            Invoice::query()->toBase()->whereIn('status', $failedInvoices),
        ));

        $gateway = self::countByTenant(
            GatewayWebhookEvent::query()->needingAttention()->toBase()->whereNotNull('tenant_id'),
        );

        // Cross-tenant, deliberately — see the class docblock.
        $ical = Tenancy::withoutTenancy(static fn (): array => self::countByTenant(
            IcalSource::query()->toBase()->where('consecutive_failures', '>=', IcalSource::ATTENTION_THRESHOLD),
        ));

        $ids = array_values(array_unique([...array_keys($mydata), ...array_keys($gateway), ...array_keys($ical)]));

        if ($ids === []) {
            return [];
        }

        // Trashed included: a cancelled operator with a failed invoice is still
        // somebody's unfinished business with the tax office.
        /** @var array<int, string> $names */
        $names = Tenant::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();

        $rows = [];

        foreach ($ids as $id) {
            $row = [
                'tenant_id' => $id,
                'name' => $names[$id] ?? ('#' . $id),
                'mydata' => $mydata[$id] ?? 0,
                'gateway_webhooks' => $gateway[$id] ?? 0,
                'ical' => $ical[$id] ?? 0,
            ];

            $rows[] = $row + ['total' => $row['mydata'] + $row['gateway_webhooks'] + $row['ical']];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return $rows;
    }

    /**
     * Gateway webhooks that could not even be matched to an operator.
     *
     * PAY-7's orphans: somebody was charged and the money has no booking. They
     * belong to no row in the per-operator table, which is exactly why they
     * need a line of their own.
     */
    public function orphanGatewayWebhooks(): int
    {
        return GatewayWebhookEvent::query()->needingAttention()->whereNull('tenant_id')->count();
    }

    /** @return array<int, int> tenant_id => count */
    private static function countByTenant(QueryBuilder $query): array
    {
        $counts = [];

        foreach ($query->select('tenant_id', DB::raw('count(*) as aggregate'))->groupBy('tenant_id')->get() as $row) {
            $counts[(int) $row->tenant_id] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * The job class, from Laravel's queue payload.
     *
     * `displayName` is what Laravel writes for every queued job, listener and
     * mailable. An unreadable payload is reported as such rather than dropped,
     * so the total and the split always add up.
     */
    private static function className(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) && is_string($decoded['displayName'] ?? null)
            ? $decoded['displayName']
            : '?';
    }
}
