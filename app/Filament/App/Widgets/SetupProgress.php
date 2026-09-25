<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Filament\App\Pages\Setup;
use App\Filament\App\Resources\PortResource;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\SeasonResource;
use App\Filament\App\Resources\StaffResource;
use App\Filament\App\Resources\VesselResource;
use App\Models\User;
use App\Support\Authorization\Capability;
use Filament\Widgets\Widget;

/**
 * The persistent checklist, on the dashboard (SAA-10).
 *
 * SAA-10 is specific about the shape: *"a tenant that has not completed it sees
 * a persistent checklist rather than a blocking modal"*. A modal is the obvious
 * thing to build and the wrong one — an operator who opened the panel to check
 * a departure should not have to dismiss a setup guide to see it.
 *
 * ## It suppresses nothing
 *
 * The distinction from {@see FirstSteps}, which sits on the same dashboard.
 * That one replaces the figures widgets while it applies, because six zeros on
 * an operator's first afternoon is a product that looks broken. This one is an
 * addition and never a replacement: an operator with a full season's bookings
 * and an unanswered VAT question has a working dashboard with a reminder on
 * top of it, not a checklist where their figures used to be.
 *
 * ## Owner only, like the page it links to
 *
 * A crew member cannot open {@see Setup} — TEN-8 — so showing them a checklist
 * of things they are not allowed to do would be a list of six locked doors.
 */
class SetupProgress extends Widget
{
    protected static string $view = 'filament.app.widgets.setup-progress';

    /**
     * Above `FirstSteps`, which is sort 0.
     *
     * Filament sorts ascending, so a negative number is how a widget goes
     * first. The account has to exist before the catalogue does, and the two
     * lists read in that order.
     */
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (! SetupChecklist::applies()) {
            return false;
        }

        $user = auth()->user();

        return $user instanceof User && $user->hasCapability(Capability::ManageBilling);
    }

    /** @return array<string, bool> */
    public function getSteps(): array
    {
        // The guide's questions, without the closing «Έτοιμοι» screen.
        return array_intersect_key(SetupChecklist::state(), array_flip(SetupChecklist::questions()));
    }

    /**
     * The catalogue, which the guide stopped asking for.
     *
     * Product owner, 2026-09-22: *«λέω να φύγουν … αλλά μετά κάπως πρέπει να
     * φαίνονται ότι πρέπει να συμπληρωθούν, αλλά όχι μέσα στα steps»*. Here is
     * that «κάπως»: a second, quieter line under the guide's own, where each
     * item is a link to the screen that owns it rather than a step of a wizard.
     *
     * @return array<string, bool>
     */
    public function getCatalogue(): array
    {
        return array_intersect_key(SetupChecklist::state(), array_flip(SetupChecklist::catalogueSteps()));
    }

    /**
     * Where each of those goes — the real screen, never a copy of it.
     *
     * @return array<string, string>
     */
    public function getCatalogueLinks(): array
    {
        return [
            SetupChecklist::PORT => PortResource::getUrl('create'),
            SetupChecklist::VESSEL => VesselResource::getUrl('create'),
            SetupChecklist::SEASON => SeasonResource::getUrl('index'),
            SetupChecklist::CREW => StaffResource::getUrl('index'),
            SetupChecklist::PRODUCT => ProductResource::getUrl('create'),
        ];
    }

    /** @return list<string> */
    public function getSkipped(): array
    {
        return SetupChecklist::skipped();
    }

    /** @return array{done: int, total: int} */
    public function getProgress(): array
    {
        return SetupChecklist::progress();
    }

    public function getNextStep(): ?string
    {
        return SetupChecklist::next();
    }

    /**
     * Where the «Συνέχεια» button goes.
     *
     * The wizard's own URL, and the wizard decides which step to open on. A
     * link that carried a step number would be a second opinion about where the
     * operator left off, computed here and stale by the time it is clicked.
     */
    public function getSetupUrl(): string
    {
        return Setup::getUrl();
    }
}
