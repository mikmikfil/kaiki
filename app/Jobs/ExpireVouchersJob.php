<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\VoucherStatus;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Marks the day's expired vouchers, per tenant, in the tenant's own day
 * (spec PRC-21, OPS-16).
 *
 * ## Why a sweeper at all, when `hasExpired()` already answers
 *
 * {@see Voucher::hasExpired()} compares the timestamp and is the authority at
 * the moment of redemption — a voucher cannot be spent past its expiry whether
 * or not this job has run. So the sweeper changes nothing about correctness.
 *
 * What it changes is what an **operator sees**. Without it the list shows a
 * voucher as `active` for ever, and the operator telephoning a guest to say
 * "your credit is still good" is reading a status the checkout will refuse.
 * The status column has to agree with the answer the guest gets.
 *
 * ## Per tenant, because a day is a local thing
 *
 * PRC-21: *"Voucher expiry is evaluated at end of day in the tenant
 * timezone."* One cross-tenant `where('expires_at', '<', now())` would be
 * simpler and would expire an Aegean operator's vouchers three hours before
 * their own day ended — a guest told their credit expired on a date their
 * calendar says has not arrived, which is the kind of thing that reaches a
 * consumer protection body rather than a support inbox.
 *
 * So the boundary is computed in each tenant's zone: end of *yesterday*, local.
 * A voucher expiring today is still spendable today, all day, wherever the
 * server happens to be.
 *
 * ## Only `active` rows
 *
 * `redeemed` is spent and `cancelled` was withdrawn; rewriting either to
 * `expired` would erase what actually happened to it. Expiry is a thing that
 * befalls a voucher nobody used.
 */
class ExpireVouchersJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $tenants = Tenancy::withoutTenancy(
            static fn () => Tenant::query()->orderBy('id')->get(),
        );

        foreach ($tenants as $tenant) {
            Tenancy::forTenant($tenant, function () use ($tenant): void {
                $this->sweep($tenant);
            });
        }
    }

    /**
     * Everything whose expiry fell before this tenant's day began.
     *
     * `endOfYesterday` in the tenant's zone, converted to UTC for the column.
     * A voucher whose `expires_at` is *today* survives this run and is caught
     * by tomorrow's — which is what "end of day" means and what a guest holding
     * a voucher dated today would expect.
     */
    private function sweep(Tenant $tenant): void
    {
        $boundary = Carbon::now($tenant->timezone)->startOfDay()->utc();

        Voucher::query()
            ->where('status', VoucherStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $boundary)
            ->update([
                'status' => VoucherStatus::Expired,
                'updated_at' => Carbon::now(),
            ]);
    }
}
