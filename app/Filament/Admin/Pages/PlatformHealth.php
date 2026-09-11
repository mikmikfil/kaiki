<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Platform\Support\PlatformHealthReport;
use App\Filament\Admin\Resources\TenantResource;
use App\Models\User;
use Carbon\CarbonInterval;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * «Υγεία πλατφόρμας» — SAA-17 on one screen.
 *
 * A Page rather than a Resource for the reason `Failures` is one in `/app`: it
 * is five sources and no single model. It reads through
 * {@see PlatformHealthReport}, which is where the cross-tenant queries live and
 * say why.
 *
 * ## What it deliberately does not do: retry or forget a failed job
 *
 * SEC-16 wants every platform write confirmed and recorded with actor, time and
 * reason, and the only trail this product has is the **operator's**
 * (`audit_logs.tenant_id` is required). A failed job belongs to no operator
 * reliably — a mail to a guest, a nightly sweep, a platform sweep — so there is
 * nowhere honest to record "the platform owner re-ran this". Rather than a
 * button whose act goes unrecorded, the screen shows each job's uuid and names
 * the two server commands (`queue:retry`, `queue:forget`), which are Laravel's
 * own and leave their own trace in the server's shell history. A platform-level
 * trail is its own decision.
 *
 * ## The super-admin only, asserted here as well as at the panel
 *
 * `canAccessPanel` already refuses an operator at `/admin`. A page has no model
 * to hang a policy on and Filament allows what it cannot check, so the same
 * rule is repeated here — the reason `TenantPolicy` is asserted rather than
 * assumed.
 */
class PlatformHealth extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'health';

    protected static string $view = 'filament.admin.pages.platform-health';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function getNavigationLabel(): string
    {
        return __('platform.health.nav');
    }

    public function getTitle(): string
    {
        return __('platform.health.title');
    }

    public function getSubheading(): ?string
    {
        return __('platform.health.subheading');
    }

    public function report(): PlatformHealthReport
    {
        return app(PlatformHealthReport::class);
    }

    /** "3 minutes", "2 hours 5 minutes" — the queue's oldest wait, as a person says it. */
    public function age(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        return CarbonInterval::seconds($seconds)->cascade()->forHumans(['parts' => 2]);
    }

    /** The operator's own record in the merchant list, where the platform acts on it. */
    public function tenantUrl(int $tenantId): string
    {
        return TenantResource::getUrl('edit', ['record' => $tenantId], panel: 'admin');
    }

    /** `App\Jobs\DeliverWebhook` reads better as `DeliverWebhook`. */
    public function shortClass(string $class): string
    {
        return class_basename($class);
    }
}
