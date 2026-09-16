<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ProductStatus;
use App\Jobs\PublishProductChange;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Where a trip stood before it was written, handed to {@see PublishProductChange}.
 *
 * ## An observer, not a line in `SaveProduct`
 *
 * The panel's form is one writer of a product. The import, the restore button,
 * a console fix-up and the soft delete are others, and a website mirroring the
 * catalogue is as out of date after any of them. The model is the one place
 * every one of them passes through.
 *
 * ## The job waits for the commit; the observer does not
 *
 * `SaveProduct` writes inside a transaction and can still refuse — the
 * publishing checklist throws after the save. A webhook queued before the
 * commit would announce a change that then rolled back, so the job is
 * dispatched from `DB::afterCommit()`.
 *
 * The observer itself runs straight away, and has to: "where did this trip
 * stand before" is `getOriginal()`, and by the time a transaction commits the
 * model has already synced its originals to the new values. An after-commit
 * observer would read every save as visible-to-visible.
 *
 * ## Only what a guest could notice
 *
 * A save that moved nothing but `updated_at` is a touch, not a change. Anything
 * else — a title, a badge, a highlight, a photo, the order, the price — is.
 */
final class ProductWebhookObserver
{
    /** Columns whose change alone is not news to anybody outside. */
    private const IGNORED = ['updated_at'];

    public function created(Product $product): void
    {
        // It did not exist, so nobody could see it.
        $this->queue($product, wasPublic: false);
    }

    public function updated(Product $product): void
    {
        if (array_diff(array_keys($product->getChanges()), self::IGNORED) === []) {
            return;
        }

        $this->queue($product, wasPublic: self::wasPublic($product));
    }

    public function deleted(Product $product): void
    {
        // `restore()` is a save, so `updated` covers the way back; this is only
        // the way in. A soft delete writes `deleted_at` by query and syncs the original, so
        // the "before" is the status alone: it was not deleted a moment ago.
        $this->queue($product, wasPublic: $product->status === ProductStatus::Active);
    }

    private function queue(Product $product, bool $wasPublic): void
    {
        $job = new PublishProductChange(
            (int) $product->getKey(),
            (int) $product->tenant_id,
            (string) $product->uuid,
            (string) $product->slug,
            $wasPublic,
        );

        // Dispatched from the commit callback rather than with the job's own
        // `afterCommit()`: the uniqueness lock is taken at dispatch, and a lock
        // taken for a save that then rolled back would silence the next real
        // change to this trip until it expired.
        DB::afterCommit(static function () use ($job): void {
            dispatch($job->delay(PublishProductChange::COALESCE_SECONDS));
        });
    }

    /** Visible before this save: switched on, and not in the bin. */
    private static function wasPublic(Product $product): bool
    {
        $status = $product->getOriginal('status');

        if (! $status instanceof ProductStatus) {
            $status = ProductStatus::tryFrom((string) $status);
        }

        return $status === ProductStatus::Active && $product->getOriginal('deleted_at') === null;
    }
}
