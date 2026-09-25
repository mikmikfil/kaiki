<?php

declare(strict_types=1);

namespace App\Filament\App\Support;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Widgets\FirstSteps;
use Livewire\Attributes\Locked;

/**
 * Back to the checklist once its step is done (Mike, 25/9).
 *
 * *«Όταν κάνεις κάτι από τη λίστα, π.χ. βάζεις τα λιμάνια, να σε ξαναπηγαίνει
 * στα επόμενα βήματα.»* An operator who followed «Προσθήκη λιμανιού» from the
 * dashboard and saved the port was left on the port's edit page, with the
 * next step one menu away and nothing saying so.
 *
 * The checklist's links carry `?from=first-steps`
 * ({@see FirstSteps::linkFromChecklist()}). A page using this trait reads it
 * once, on mount, into a locked property — so it survives every Livewire
 * round trip of the form and cannot be switched on from the browser — and
 * sends the operator back to the dashboard when the step is done. Without the
 * marker nothing changes: the same page reached from its own menu behaves as
 * it always has.
 */
trait ReturnsToFirstSteps
{
    #[Locked]
    public bool $fromFirstSteps = false;

    /** Livewire calls `mount` + the trait's name on every page that uses it. */
    public function mountReturnsToFirstSteps(): void
    {
        $this->fromFirstSteps = request()->query('from') === FirstSteps::MARKER;
    }

    /** The dashboard when the page was opened from the checklist, `$otherwise` when not. */
    protected function firstStepsRedirectUrl(string $otherwise): string
    {
        return $this->fromFirstSteps ? Dashboard::getUrl() : $otherwise;
    }

    /** A URL of this panel carrying the marker on, for a page that hands over to another. */
    protected function keepFirstSteps(string $url): string
    {
        return $this->fromFirstSteps ? FirstSteps::linkFromChecklist($url) : $url;
    }
}
