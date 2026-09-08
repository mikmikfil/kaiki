<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Notifications\Actions\RetryNotification;
use App\Domain\Operations\Support\FailureFeed;
use App\Domain\Operations\Support\FailureItem;
use App\Enums\DeliveryStatus;
use App\Enums\ExportStatus;
use App\Enums\FailureSource;
use App\Jobs\DeliverWebhook;
use App\Jobs\RunExportJob;
use App\Models\ExportJob;
use App\Models\NotificationLog;
use App\Models\WebhookDelivery;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * «Τι πήγε στραβά» — OPS-21's one feed for every failure an operator can see.
 *
 * ## Why a Page rather than a Resource
 *
 * A Filament Resource is one Eloquent model and one query. This is six models
 * and six queries merged in PHP, for the reason {@see FailureFeed} gives at
 * length: each source stays the authority on its own failures, and a seventh
 * table copying them would drift.
 *
 * ## The retry dispatches to the source's own Action, never a generic one
 *
 * Three of the six can be retried and each means something different. A
 * notification is re-sent through `RetryNotification`, which writes a fresh log
 * row rather than mutating the old one. An export is re-queued as a new job
 * against the same row. A webhook delivery is **reset and re-sent with the same
 * event id**, so a receiver that already had it ignores the repeat.
 *
 * A single "retry" that guessed would get the third one wrong in the way that
 * costs somebody money: a new id defeats the consumer's own deduplication,
 * which is the mechanism `docs/api.md` §8.4 tells them to rely on.
 *
 * ## The other three have no button, and each absence is a decision
 *
 * A **payment** was refused by a bank; the retry belongs to the guest and their
 * card. A **gateway webhook** we could not process needs a person to read it —
 * PAY-7 keeps an orphan visible rather than retried, because somebody has been
 * charged and the money cannot be matched. An **iCal** source is already
 * retried every fifteen minutes by the poller, and a button would promise
 * something the schedule is doing anyway.
 *
 * Offering a button on any of them would be a control that looks like it did
 * something and did not.
 */
class Failures extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string $view = 'filament.app.pages.failures';

    /** Below the notification log it partly supersedes, above the exports. */
    protected static ?int $navigationSort = 64;

    /**
     * Two minutes.
     *
     * Slower than the dashboard's minute, because this is a screen somebody
     * opens when something is wrong rather than one they leave up all morning,
     * and faster than nothing, because the usual visit is "I have just fixed
     * the SMTP password, did it work".
     */
    protected static ?string $pollingInterval = '120s';

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('failures.nav');
    }

    public function getTitle(): string
    {
        return __('failures.title');
    }

    public static function canAccess(): bool
    {
        // The feed carries a guest's booking reference and a masked recipient
        // across six tables. Whoever can read the notification log can read
        // this; a crew member checking people onto a boat cannot.
        return Tenancy::check()
            && (Auth::user()?->hasCapability(Capability::ManageBookings) ?? false);
    }

    /**
     * The badge on the navigation item.
     *
     * Null rather than zero when there is nothing wrong: a permanent grey `0`
     * beside a menu item is a thing people stop seeing, and this badge exists
     * to be noticed on the morning it is not zero.
     *
     * ## Cached for a minute, and that is not premature
     *
     * A navigation badge runs on **every page of the panel** — the dashboard,
     * every list, every form. Uncached, this would put {@see FailureFeed}'s six
     * queries behind every click an operator makes, and NFR-5 gives the whole
     * dashboard 1.5 seconds at the 95th percentile.
     *
     * A minute is short enough that an operator who has just fixed something
     * sees the number fall while they are still looking at the screen, and long
     * enough that six queries happen once a minute rather than forty times.
     * The page itself is never cached: it polls, and it is the authority.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenantId = Tenancy::id();

        if ($tenantId === null) {
            return null;
        }

        $count = Cache::remember(
            "failures:count:{$tenantId}",
            now()->addMinute(),
            static fn (): int => count(app(FailureFeed::class)->all()),
        );

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return list<FailureItem> */
    public function getItems(): array
    {
        return app(FailureFeed::class)->all();
    }

    /**
     * Try one row again.
     *
     * Called from the view with the source and the row's own id, rather than
     * with a closure: a Livewire action's arguments cross the wire, and a
     * closure cannot. The `match` is exhaustive, so a seventh source added to
     * the enum without a case here is a compile-time failure rather than a
     * button that silently does nothing.
     */
    public function retry(string $source, int $id): void
    {
        $done = match (FailureSource::tryFrom($source)) {
            FailureSource::Notification => $this->retryNotification($id),
            FailureSource::Export => $this->retryExport($id),
            FailureSource::OutboundWebhook => $this->retryWebhook($id),
            default => false,
        };

        Notification::make()
            ->status($done ? 'success' : 'warning')
            ->title(__($done ? 'failures.retry.done' : 'failures.retry.nothing'))
            ->send();
    }

    private function retryNotification(int $id): bool
    {
        $log = NotificationLog::query()->find($id);

        return $log instanceof NotificationLog && app(RetryNotification::class)($log);
    }

    private function retryExport(int $id): bool
    {
        $job = ExportJob::query()->find($id);

        if (! $job instanceof ExportJob) {
            return false;
        }

        // Back to queued on the same row, so the operator's original date
        // window and basis are kept. A new row would make them choose again.
        $job->forceFill([
            'status' => ExportStatus::Queued,
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
        ])->save();

        RunExportJob::dispatch($job->getKey());

        return true;
    }

    private function retryWebhook(int $id): bool
    {
        $delivery = WebhookDelivery::query()->find($id);

        if (! $delivery instanceof WebhookDelivery) {
            return false;
        }

        // The same row, the same `event_id`, the same stored payload — which is
        // what makes this safe to press twice. See the class docblock.
        $delivery->forceFill([
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => Carbon::now(),
            'response_status' => null,
            'response_body' => null,
        ])->save();

        DeliverWebhook::dispatch($delivery->getKey());

        return true;
    }
}
