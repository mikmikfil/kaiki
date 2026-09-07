<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Domain\Operations\Support\FirstSteps as Steps;
use App\Filament\App\Resources\DepartureResource;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\VesselResource;
use Filament\Widgets\Widget;

/**
 * The dashboard, for an operator who has not taken a booking yet (spec OPS-1).
 *
 * Six zeros and a link to an empty list is what the figures widget shows on an
 * operator's first afternoon, and it is a product that looks broken on the day
 * somebody decides whether to keep paying for it. This replaces it until there
 * is something to count.
 *
 * One step at a time, in the order the availability engine requires them. A
 * checklist of four open items is a decision about where to start, and the
 * value of this panel is that there is nothing to decide.
 */
class FirstSteps extends Widget
{
    protected static string $view = 'filament.app.widgets.first-steps';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Steps::applies();
    }

    /**
     * @return array<string, bool>
     */
    public function getSteps(): array
    {
        return Steps::state();
    }

    public function getNextStep(): ?string
    {
        return Steps::next();
    }

    /**
     * Where each step is done.
     *
     * Built here rather than in the view: a resource URL is a route lookup, and
     * a Blade template calling four of them is a template that fails at render
     * time on the one route somebody renamed.
     *
     * @return array<string, string>
     */
    public function getLinks(): array
    {
        return [
            Steps::VESSEL => VesselResource::getUrl('create'),
            Steps::PRODUCT => ProductResource::getUrl('create'),
            Steps::PUBLISHED => ProductResource::getUrl('index'),
            Steps::DEPARTURE => DepartureResource::getUrl('index'),
        ];
    }
}
